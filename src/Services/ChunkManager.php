<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Services;

use Closure;
use finfo;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Muhpuc40\Chunkit\Contracts\ChunkStorage;
use Muhpuc40\Chunkit\Jobs\MergeChunksJob;
use Muhpuc40\Chunkit\Models\ChunkedUpload;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * All of the upload rules live here; the controller and the job are thin.
 *
 * Errors are signalled with framework exceptions that already carry an HTTP
 * meaning, so the controller can turn them into JSON without a lookup table:
 *
 *  - ValidationException      -> 422 (bad input, wrong chunk size, rejected file)
 *  - NotFoundHttpException    -> 404 (unknown upload)
 *  - ConflictHttpException    -> 409 (offset mismatch, busy, wrong state)
 *  - GoneHttpException        -> 410 (upload expired)
 *
 * Anything else is a genuine server fault and is allowed to bubble up.
 */
class ChunkManager
{
    /**
     * Bytes read from the start of the merged file to detect its real type.
     * This matches libmagic's own default scan limit.
     */
    private const SNIFF_BYTES = 1048576;

    /**
     * How long one merge may hold its lock. Comfortably above the job's
     * 600 second timeout, so a crashed worker cannot block a retry for long.
     */
    private const MERGE_LOCK_SECONDS = 900;

    public function __construct(
        private readonly ChunkStorage $storage,
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly Dispatcher $bus,
        private readonly FilesystemFactory $disks,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Start an upload. The chunk size always comes from config, never from the client.
     *
     * @throws ValidationException When the name, size or declared type is not acceptable.
     * @throws RuntimeException When chunk_size is misconfigured.
     */
    public function initiate(string $originalName, int $totalBytes, ?string $mimeType = null): ChunkedUpload
    {
        $chunkSize = $this->intConfig('chunk_size');
        $maxChunkSize = $this->intConfig('max_chunk_size');
        $maxTotalSize = $this->intConfig('max_total_size');

        if ($chunkSize < 1 || $chunkSize > $maxChunkSize) {
            throw new RuntimeException(
                "Chunkit is misconfigured: chunk_size ({$chunkSize}) must be between 1 and max_chunk_size ({$maxChunkSize})."
            );
        }

        if ($totalBytes < 1 || $totalBytes > $maxTotalSize) {
            throw ValidationException::withMessages([
                'total_bytes' => ["The file must be between 1 byte and {$maxTotalSize} bytes."],
            ]);
        }

        $name = $this->sanitizeFileName($originalName);

        // The declared type is only an early filter: browsers sometimes send none,
        // and the real type is verified from the merged file's bytes after the merge.
        if ($mimeType !== null && $mimeType !== '' && ! self::mimeIsAllowed($mimeType, $this->allowedMimes())) {
            throw ValidationException::withMessages([
                'mime_type' => ['Files of this type are not allowed.'],
            ]);
        }

        return ChunkedUpload::query()->create([
            'upload_id' => Str::uuid()->toString(),
            'original_name' => $name,
            'mime_type' => $mimeType !== null && $mimeType !== '' ? $mimeType : null,
            'total_chunks' => intdiv($totalBytes + $chunkSize - 1, $chunkSize),
            'chunk_size' => $chunkSize,
            'total_bytes' => $totalBytes,
            'received_chunks' => [],
            'status' => ChunkedUpload::STATUS_PENDING,
            'expires_at' => $this->newExpiry(),
        ]);
    }

    /**
     * Current state of an upload. Chunks the database lists but the disk no
     * longer holds are dropped first, so the reported offset is always honest.
     *
     * @throws NotFoundHttpException When the upload does not exist.
     * @throws ConflictHttpException When the upload is busy and the state cannot be read safely.
     */
    public function status(string $uploadId): ChunkedUpload
    {
        $upload = $this->find($uploadId);

        if (! $upload->acceptsChunks()) {
            return $upload;
        }

        if (array_diff($upload->receivedIndexes(), $this->storage->listChunks($uploadId)) === []) {
            return $upload;
        }

        return $this->withUploadLock(
            $uploadId,
            fn (): ChunkedUpload => $this->reconcile($this->find($uploadId)),
        );
    }

    /**
     * Store one chunk. Safe to retry: a chunk that is already stored is a no-op.
     *
     * The client's offset must be the start byte of THIS chunk (index * chunk_size).
     * When it is not, the 409 carries the server's real resume point in its
     * Upload-Offset header: the first gap in the received chunks.
     *
     * @param  resource  $stream  Readable stream holding exactly this chunk.
     *
     * @throws InvalidArgumentException When $stream is not a stream.
     * @throws ValidationException When the index is out of range or the chunk has the wrong size.
     * @throws NotFoundHttpException When the upload does not exist.
     * @throws ConflictHttpException When the offset is wrong, the upload is busy, or it no longer accepts chunks.
     * @throws GoneHttpException When the upload has expired.
     * @throws RuntimeException When storage fails.
     */
    public function storeChunk(string $uploadId, int $index, int $clientOffset, $stream): ChunkedUpload
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Chunkit: storeChunk() needs a readable stream resource.');
        }

        $stats = fstat($stream);
        $size = is_array($stats) ? (int) $stats['size'] : -1;

        return $this->withUploadLock($uploadId, function () use ($uploadId, $index, $clientOffset, $stream, $size): ChunkedUpload {
            $upload = $this->find($uploadId);

            $this->assertAcceptsChunks($upload);

            if ($index < 0 || $index >= $upload->total_chunks) {
                $last = $upload->total_chunks - 1;

                throw ValidationException::withMessages([
                    'index' => ["The chunk index must be between 0 and {$last}."],
                ]);
            }

            $expectedOffset = $upload->chunkOffset($index);

            if ($clientOffset !== $expectedOffset) {
                throw $this->offsetConflict(
                    "Upload-Offset {$clientOffset} does not match the start of chunk {$index} ({$expectedOffset}). "
                    ."Resume from {$upload->offset()}.",
                    $upload,
                );
            }

            $expectedSize = $upload->expectedChunkSize($index);

            if ($size !== $expectedSize) {
                throw ValidationException::withMessages([
                    'chunk' => ["Chunk {$index} must be exactly {$expectedSize} bytes."],
                ]);
            }

            // Idempotent retry: already recorded and still intact on disk.
            if ($upload->hasChunk($index) && $this->chunkIsIntact($uploadId, $index, $expectedSize)) {
                return $upload;
            }

            $stored = $this->storage->putChunk($uploadId, $index, $stream);

            if ($stored !== $expectedSize) {
                throw new RuntimeException(
                    "Chunkit: chunk {$index} of upload {$uploadId} was stored as {$stored} bytes, expected {$expectedSize}."
                );
            }

            // Only now, with the chunk safely on disk, is it recorded as received.
            $received = $upload->receivedIndexes();
            $received[] = $index;
            $received = array_values(array_unique($received));
            sort($received);

            $upload->update([
                'received_chunks' => $received,
                'status' => ChunkedUpload::STATUS_UPLOADING,
                'expires_at' => $this->newExpiry(),
            ]);

            return $upload;
        });
    }

    /**
     * Mark the upload as merging and queue the merge. Never merges inline.
     *
     * Calling it again while the upload is merging or completed changes nothing.
     * A failed upload whose chunks are all still on disk is queued again.
     *
     * @throws ValidationException When chunks are still missing.
     * @throws NotFoundHttpException When the upload does not exist.
     * @throws ConflictHttpException When the upload is busy.
     * @throws GoneHttpException When the upload has expired.
     * @throws Throwable When the job cannot be queued; the upload is put back as it was.
     */
    public function complete(string $uploadId): ChunkedUpload
    {
        return $this->withUploadLock($uploadId, function () use ($uploadId): ChunkedUpload {
            $upload = $this->find($uploadId);

            if (in_array($upload->status, [ChunkedUpload::STATUS_COMPLETED, ChunkedUpload::STATUS_MERGING], true)) {
                return $upload;
            }

            $this->assertNotExpired($upload);

            $upload = $this->reconcile($upload);

            if (! $upload->isComplete()) {
                $received = count($upload->receivedIndexes());

                throw ValidationException::withMessages([
                    'upload' => [
                        $upload->status === ChunkedUpload::STATUS_FAILED
                            ? 'This upload failed and its chunks are gone. Start a new upload.'
                            : "The upload is incomplete: {$received} of {$upload->total_chunks} chunks received.",
                    ],
                ]);
            }

            $previous = $upload->status;

            $upload->update([
                'status' => ChunkedUpload::STATUS_MERGING,
                'failure_reason' => null,
                'expires_at' => $this->newExpiry(),
            ]);

            try {
                $this->dispatchMerge($uploadId);
            } catch (Throwable $e) {
                $upload->update(['status' => $previous]);

                throw $e;
            }

            return $upload;
        });
    }

    /**
     * Assemble the chunks into the final file. Called by MergeChunksJob.
     *
     * Idempotent and retry-safe: a completed upload only has its leftover
     * chunks cleaned up, and a second worker that finds a merge already
     * running simply steps aside.
     *
     * After the file is assembled its REAL type is read from its magic bytes
     * and checked against allowed_mimes before the upload is marked completed.
     * On a mismatch (or a size mismatch) the assembled file and the chunks are
     * deleted, the upload is marked failed, and a ValidationException (the 422
     * case) is thrown. The job treats that as final and does not retry it.
     *
     * @throws ValidationException When the finished file is rejected.
     * @throws RuntimeException When storage fails; the job retries these.
     */
    public function merge(string $uploadId): void
    {
        $lock = $this->lockFor("chunkit:{$uploadId}:merge", self::MERGE_LOCK_SECONDS);

        if (! $lock->get()) {
            $this->logger->info('Chunkit: another worker is already merging this upload, skipping.', [
                'upload_id' => $uploadId,
            ]);

            return;
        }

        try {
            $this->mergeLocked($uploadId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete unfinished uploads whose expiry has passed: the row and the chunk
     * directory. One upload failing never stops the others.
     *
     * @return array{deleted: int, failed: int}
     */
    public function pruneAbandoned(bool $dryRun = false): array
    {
        $deleted = 0;
        $failed = 0;

        ChunkedUpload::query()->abandoned()->chunkById(100, function ($uploads) use ($dryRun, &$deleted, &$failed): void {
            foreach ($uploads as $upload) {
                if ($dryRun) {
                    $deleted++;

                    continue;
                }

                try {
                    if ($this->pruneOne($upload->upload_id)) {
                        $deleted++;
                    }
                } catch (Throwable $e) {
                    $failed++;

                    $this->logger->error('Chunkit: could not prune an abandoned upload.', [
                        'upload_id' => $upload->upload_id,
                        'exception' => $e,
                    ]);
                }
            }
        });

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Turn a client-supplied file name into one that is safe to put on disk.
     *
     * Directory parts are dropped, control and invisible characters removed,
     * characters Windows forbids replaced, whitespace collapsed, the extension
     * lowercased, and the length capped. Every dot-separated part after the
     * first is checked against blocked_extensions, so "shell.php.jpg" is
     * refused along with "shell.php".
     *
     * @throws ValidationException When nothing usable is left or the extension is blocked.
     */
    public function sanitizeFileName(string $name): string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            throw $this->invalidName();
        }

        $segments = explode('/', str_replace('\\', '/', $name));
        $name = (string) end($segments);

        $name = (string) preg_replace('/[\p{C}]+/u', '', $name);
        $name = (string) preg_replace('/[<>:"|?*]+/', '_', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = trim($name, ' .');

        $base = $name;
        $extension = '';

        if (preg_match('/^(.+)\.([A-Za-z0-9]{1,10})$/su', $name, $matches) === 1) {
            $base = rtrim($matches[1], ' .');
            $extension = mb_strtolower($matches[2]);
        }

        if ($base === '') {
            throw $this->invalidName();
        }

        if (preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])$/i', $base) === 1) {
            $base = '_'.$base;
        }

        $maxBase = 255 - ($extension === '' ? 0 : strlen($extension) + 1);

        if (strlen($base) > $maxBase) {
            $base = rtrim(mb_strcut($base, 0, $maxBase, 'UTF-8'), ' .');
        }

        $sanitized = $extension === '' ? $base : $base.'.'.$extension;

        $blocked = array_map(
            static fn (mixed $extension): string => strtolower(ltrim((string) $extension, '.')),
            (array) $this->config->get('chunkit.blocked_extensions', []),
        );

        foreach (array_slice(explode('.', mb_strtolower($sanitized)), 1) as $part) {
            if (in_array($part, $blocked, true)) {
                throw ValidationException::withMessages([
                    'original_name' => ['Files with this extension are not allowed.'],
                ]);
            }
        }

        return $sanitized;
    }

    /**
     * Whether a MIME type matches an allow list of exact types ("video/mp4")
     * and family wildcards ("video/*"). An empty list allows nothing.
     *
     * @param  array<int, string>  $allowed
     */
    public static function mimeIsAllowed(string $mime, array $allowed): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if ($mime === '') {
            return false;
        }

        foreach ($allowed as $pattern) {
            $pattern = strtolower(trim((string) $pattern));

            if ($pattern === $mime) {
                return true;
            }

            if (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ValidationException
     * @throws RuntimeException
     */
    private function mergeLocked(string $uploadId): void
    {
        $upload = ChunkedUpload::query()->where('upload_id', $uploadId)->first();

        if ($upload === null) {
            $this->logger->warning('Chunkit: merge skipped, the upload no longer exists.', ['upload_id' => $uploadId]);

            return;
        }

        if ($upload->status === ChunkedUpload::STATUS_COMPLETED) {
            // A retry after the file was published: only the leftover cleanup can still be owed.
            $this->storage->deleteUpload($uploadId);

            return;
        }

        if ($upload->status !== ChunkedUpload::STATUS_MERGING) {
            $this->logger->info('Chunkit: merge skipped, the upload is not waiting for a merge.', [
                'upload_id' => $uploadId,
                'status' => $upload->status,
            ]);

            return;
        }

        $disk = (string) $this->config->get('chunkit.final_disk');
        $path = $this->destinationPath($upload);

        $bytes = $this->storage->assemble($uploadId, $upload->total_chunks, $disk, $path);

        if ($bytes !== $upload->total_bytes) {
            $this->reject(
                $upload,
                $disk,
                $path,
                "assembled {$bytes} bytes but {$upload->total_bytes} were declared",
                'The assembled file does not match the declared size.',
            );
        }

        $detected = $this->detectMimeType($disk, $path);

        if (! self::mimeIsAllowed($detected, $this->allowedMimes())) {
            $this->reject(
                $upload,
                $disk,
                $path,
                'detected type ['.($detected === '' ? 'unknown' : $detected).'] is not allowed',
                'The content of the file is not an allowed type.',
            );
        }

        $upload->update([
            'status' => ChunkedUpload::STATUS_COMPLETED,
            'destination_path' => $path,
        ]);

        $this->storage->deleteUpload($uploadId);
    }

    /**
     * Fail an upload whose merged file is unacceptable: mark it failed, remove
     * the assembled file and the chunks, then signal a 422.
     *
     * @throws ValidationException Always.
     */
    private function reject(ChunkedUpload $upload, string $disk, string $path, string $reason, string $message): never
    {
        $upload->update([
            'status' => ChunkedUpload::STATUS_FAILED,
            'failure_reason' => $message,
        ]);

        $this->disks->disk($disk)->delete($path);
        $this->storage->deleteUpload($upload->upload_id);

        $this->logger->warning('Chunkit: merged file rejected.', [
            'upload_id' => $upload->upload_id,
            'reason' => $reason,
        ]);

        throw ValidationException::withMessages(['file' => [$message]]);
    }

    /**
     * The file's real type, read from its magic bytes. The client-declared type
     * and the file name play no part. Returns '' when it cannot be determined.
     *
     * @throws RuntimeException When the fileinfo extension is missing or the merged file cannot be opened.
     */
    private function detectMimeType(string $disk, string $path): string
    {
        if (! class_exists(finfo::class)) {
            throw new RuntimeException('Chunkit requires the fileinfo extension.');
        }

        $stream = $this->disks->disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Chunkit: could not open the merged file [{$path}] to check its type.");
        }

        try {
            $head = fread($stream, self::SNIFF_BYTES);
        } finally {
            fclose($stream);
        }

        if (! is_string($head) || $head === '') {
            return '';
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return is_string($mime) ? $mime : '';
    }

    /**
     * Where the finished file goes: final_path_pattern with the upload id and
     * the already-sanitised name substituted in.
     */
    private function destinationPath(ChunkedUpload $upload): string
    {
        $pattern = (string) $this->config->get('chunkit.final_path_pattern', 'uploads/{uuid}/{original_name}');

        return ltrim(str_replace(
            ['{uuid}', '{original_name}'],
            [$upload->upload_id, $upload->original_name],
            $pattern,
        ), '/');
    }

    private function pruneOne(string $uploadId): bool
    {
        $mergeLock = $this->lockFor("chunkit:{$uploadId}:merge", self::MERGE_LOCK_SECONDS);

        // A merge is running right now, so the upload is not abandoned.
        if (! $mergeLock->get()) {
            return false;
        }

        try {
            $uploadLock = $this->lockFor("chunkit:{$uploadId}", $this->intConfig('lock_seconds'));

            return $uploadLock->get(function () use ($uploadId): bool {
                // Re-check under the lock: a late chunk may have revived it.
                $upload = ChunkedUpload::query()->abandoned()->where('upload_id', $uploadId)->first();

                if ($upload === null) {
                    return false;
                }

                $this->storage->deleteUpload($uploadId);
                $upload->delete();

                return true;
            }) === true;
        } finally {
            $mergeLock->release();
        }
    }

    /**
     * Drop received chunks whose files are gone from disk, so the database
     * never claims more than the disk can deliver.
     */
    private function reconcile(ChunkedUpload $upload): ChunkedUpload
    {
        $recorded = $upload->receivedIndexes();
        $kept = array_values(array_intersect($recorded, $this->storage->listChunks($upload->upload_id)));

        if (count($kept) !== count($recorded)) {
            $this->logger->warning('Chunkit: dropped received chunks that are missing from disk.', [
                'upload_id' => $upload->upload_id,
                'dropped' => array_values(array_diff($recorded, $kept)),
            ]);

            $upload->update(['received_chunks' => $kept]);
        }

        return $upload;
    }

    private function chunkIsIntact(string $uploadId, int $index, int $expectedSize): bool
    {
        return $this->storage->hasChunk($uploadId, $index)
            && $this->storage->chunkSize($uploadId, $index) === $expectedSize;
    }

    private function dispatchMerge(string $uploadId): void
    {
        $job = new MergeChunksJob($uploadId);

        // Both default to null, which leaves the job on the host app's default queue.
        $connection = $this->config->get('chunkit.queue_connection');
        $queue = $this->config->get('chunkit.queue_name');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        $this->bus->dispatch($job);
    }

    /**
     * @throws NotFoundHttpException
     */
    private function find(string $uploadId): ChunkedUpload
    {
        $upload = ChunkedUpload::query()->where('upload_id', $uploadId)->first();

        if ($upload === null) {
            throw new NotFoundHttpException('Upload not found.');
        }

        return $upload;
    }

    /**
     * @throws ConflictHttpException
     * @throws GoneHttpException
     */
    private function assertAcceptsChunks(ChunkedUpload $upload): void
    {
        if (! $upload->acceptsChunks()) {
            throw $this->offsetConflict(
                "This upload is {$upload->status} and no longer accepts chunks.",
                $upload,
            );
        }

        $this->assertNotExpired($upload);
    }

    /**
     * @throws GoneHttpException
     */
    private function assertNotExpired(ChunkedUpload $upload): void
    {
        if ($upload->expires_at->isPast()) {
            throw new GoneHttpException('This upload has expired. Start a new upload.');
        }
    }

    /**
     * A 409 whose Upload-Offset header is the server's real resume point, the
     * first gap in the received chunks, never the offset of a rejected chunk.
     */
    private function offsetConflict(string $message, ChunkedUpload $upload): ConflictHttpException
    {
        return new ConflictHttpException($message, null, 0, ['Upload-Offset' => (string) $upload->offset()]);
    }

    /**
     * Run a callback while holding this upload's lock, so concurrent and
     * retried requests for the same upload take turns.
     *
     * @throws ConflictHttpException When the lock cannot be taken within lock_wait_seconds.
     */
    private function withUploadLock(string $uploadId, Closure $callback): mixed
    {
        $lock = $this->lockFor("chunkit:{$uploadId}", $this->intConfig('lock_seconds'));

        try {
            return $lock->block($this->intConfig('lock_wait_seconds'), $callback);
        } catch (LockTimeoutException $e) {
            $headers = [];
            $upload = ChunkedUpload::query()->where('upload_id', $uploadId)->first();

            if ($upload !== null) {
                $headers['Upload-Offset'] = (string) $upload->offset();
            }

            throw new ConflictHttpException(
                'Another request is still working on this upload. Retry shortly.',
                $e,
                0,
                $headers,
            );
        }
    }

    /**
     * @throws RuntimeException When the cache store cannot do atomic locks.
     */
    private function lockFor(string $name, int $seconds): Lock
    {
        $store = $this->cache->store();
        $provider = $store instanceof CacheRepository ? $store->getStore() : $store;

        if (! $provider instanceof LockProvider) {
            throw new RuntimeException(
                'Chunkit needs a cache store with atomic locks (file, database, redis, memcached, dynamodb or array).'
            );
        }

        return $provider->lock($name, $seconds);
    }

    private function newExpiry(): Carbon
    {
        return Carbon::now()->addHours($this->intConfig('ttl_hours'));
    }

    /**
     * @return array<int, string>
     */
    private function allowedMimes(): array
    {
        return array_values(array_map('strval', (array) $this->config->get('chunkit.allowed_mimes', [])));
    }

    private function intConfig(string $key): int
    {
        return (int) $this->config->get("chunkit.{$key}");
    }

    private function invalidName(): ValidationException
    {
        return ValidationException::withMessages([
            'original_name' => ['The file name is not valid.'],
        ]);
    }
}
