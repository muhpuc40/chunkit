<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Muhpuc40\Chunkit\Contracts\ChunkStorage;
use RuntimeException;
use Throwable;

/**
 * Stores chunks on a local-driver disk at {directory}/{uploadId}/{index}.
 *
 * With Laravel 11+'s default "local" disk that is storage/app/private/chunkit/...;
 * on an app that still uses the old default root it is storage/app/chunkit/...
 *
 * Every read and write goes through streams, so memory use stays flat no
 * matter how large the upload is.
 */
class LocalChunkStorage implements ChunkStorage
{
    private readonly string $directory;

    /**
     * @param  string  $disk  Name of the disk that holds chunks (config: chunkit.chunk_disk).
     * @param  string  $directory  Folder on that disk (config: chunkit.chunk_directory).
     *
     * @throws InvalidArgumentException When the directory is empty or unsafe.
     */
    public function __construct(
        private readonly string $disk,
        string $directory = 'chunkit',
    ) {
        $directory = trim($directory, '/\\');

        $this->assertSafePath($directory, 'chunk directory');

        $this->directory = $directory;
    }

    public function putChunk(string $uploadId, int $index, $stream): int
    {
        $this->assertUploadId($uploadId);
        $this->assertIndex($index);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Chunkit: putChunk() needs a readable stream resource.');
        }

        $path = $this->chunkPath($uploadId, $index);

        if ($this->chunkDisk()->put($path, $stream) === false) {
            throw new RuntimeException(
                "Chunkit: could not write chunk {$index} of upload {$uploadId} to disk [{$this->disk}]."
            );
        }

        return $this->chunkDisk()->size($path);
    }

    public function hasChunk(string $uploadId, int $index): bool
    {
        $this->assertUploadId($uploadId);
        $this->assertIndex($index);

        return $this->chunkDisk()->exists($this->chunkPath($uploadId, $index));
    }

    public function chunkSize(string $uploadId, int $index): int
    {
        $this->assertUploadId($uploadId);
        $this->assertIndex($index);

        $path = $this->chunkPath($uploadId, $index);

        if (! $this->chunkDisk()->exists($path)) {
            throw new RuntimeException("Chunkit: chunk {$index} of upload {$uploadId} does not exist.");
        }

        return $this->chunkDisk()->size($path);
    }

    public function readChunkStream(string $uploadId, int $index)
    {
        $this->assertUploadId($uploadId);
        $this->assertIndex($index);

        $stream = $this->chunkDisk()->readStream($this->chunkPath($uploadId, $index));

        if (! is_resource($stream)) {
            throw new RuntimeException("Chunkit: could not open chunk {$index} of upload {$uploadId} for reading.");
        }

        return $stream;
    }

    public function listChunks(string $uploadId): array
    {
        $this->assertUploadId($uploadId);

        $indexes = [];

        foreach ($this->chunkDisk()->files($this->uploadDirectory($uploadId)) as $file) {
            $name = basename($file);

            // Ignore anything that is not a chunk file (e.g. stray temp files).
            if (ctype_digit($name)) {
                $indexes[] = (int) $name;
            }
        }

        sort($indexes);

        return $indexes;
    }

    public function assemble(string $uploadId, int $totalChunks, string $disk, string $path): int
    {
        $this->assertUploadId($uploadId);
        $this->assertSafePath($path, 'destination path');

        if ($totalChunks < 1) {
            throw new InvalidArgumentException('Chunkit: an upload needs at least one chunk to assemble.');
        }

        $target = $this->localDisk($disk);

        // Fail before touching the destination if any chunk is missing.
        for ($index = 0; $index < $totalChunks; $index++) {
            if (! $this->hasChunk($uploadId, $index)) {
                throw new RuntimeException("Chunkit: cannot assemble upload {$uploadId}, chunk {$index} is missing.");
            }
        }

        $directory = dirname($path);

        if ($directory !== '.' && $directory !== '') {
            $target->makeDirectory($directory);
        }

        // Build under a temporary name so a half-written file is never visible at the final path.
        $partPath = "{$path}.{$uploadId}.part";

        try {
            $written = $this->concatenate($uploadId, $totalChunks, $target->path($partPath), $partPath);

            // Replacing explicitly keeps a retried merge safe, and avoids relying on
            // rename-over-existing semantics, which differ between operating systems.
            if ($target->exists($path)) {
                $target->delete($path);
            }

            if (! $target->move($partPath, $path)) {
                throw new RuntimeException("Chunkit: could not move the assembled file into place at [{$path}].");
            }
        } catch (Throwable $e) {
            $target->delete($partPath);

            throw $e;
        }

        return $written;
    }

    public function deleteUpload(string $uploadId): void
    {
        $this->assertUploadId($uploadId);

        $directory = $this->uploadDirectory($uploadId);

        $this->chunkDisk()->deleteDirectory($directory);

        if ($this->chunkDisk()->exists($directory)) {
            throw new RuntimeException("Chunkit: could not delete the chunk directory for upload {$uploadId}.");
        }
    }

    /**
     * Append every chunk, in order, to one local file using constant memory.
     *
     * @throws RuntimeException When the file cannot be opened or a chunk copy comes up short.
     */
    private function concatenate(string $uploadId, int $totalChunks, string $absolutePart, string $label): int
    {
        $output = fopen($absolutePart, 'wb');

        if ($output === false) {
            throw new RuntimeException("Chunkit: cannot open [{$label}] for writing.");
        }

        $written = 0;

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $expected = $this->chunkSize($uploadId, $index);
                $input = $this->readChunkStream($uploadId, $index);

                try {
                    $copied = stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                }

                if ($copied === false || $copied !== $expected) {
                    throw new RuntimeException(
                        "Chunkit: chunk {$index} of upload {$uploadId} copied short (expected {$expected} bytes)."
                    );
                }

                $written += $copied;
            }

            if (! fflush($output)) {
                throw new RuntimeException("Chunkit: could not flush [{$label}] to disk.");
            }
        } finally {
            fclose($output);
        }

        return $written;
    }

    private function chunkPath(string $uploadId, int $index): string
    {
        return $this->uploadDirectory($uploadId).'/'.$index;
    }

    private function uploadDirectory(string $uploadId): string
    {
        return $this->directory.'/'.$uploadId;
    }

    private function chunkDisk(): FilesystemAdapter
    {
        return $this->localDisk($this->disk);
    }

    /**
     * @throws RuntimeException When the disk does not use the local driver.
     */
    private function localDisk(string $disk): FilesystemAdapter
    {
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            throw new RuntimeException(
                "Chunkit v1 only supports local-driver disks, and [{$disk}] is not one."
            );
        }

        return $this->resolveDisk($disk);
    }

    private function resolveDisk(string $disk): FilesystemAdapter
    {
        /** @var FilesystemAdapter $filesystem */
        $filesystem = Storage::disk($disk);

        return $filesystem;
    }

    /**
     * Upload ids become directory names, so only real UUIDs are allowed.
     *
     * @throws InvalidArgumentException
     */
    private function assertUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new InvalidArgumentException('Chunkit: the upload id must be a UUID.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertIndex(int $index): void
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Chunkit: the chunk index cannot be negative.');
        }
    }

    /**
     * Reject empty, absolute, drive-letter and parent-traversal paths.
     *
     * @throws InvalidArgumentException
     */
    private function assertSafePath(string $path, string $what): void
    {
        $normalised = str_replace('\\', '/', $path);

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($normalised, '/')
            || preg_match('/^[A-Za-z]:/', $normalised) === 1
            || in_array('..', explode('/', $normalised), true)
        ) {
            throw new InvalidArgumentException("Chunkit: the {$what} is empty or unsafe.");
        }
    }
}
