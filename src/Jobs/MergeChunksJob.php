<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Muhpuc40\Chunkit\Models\ChunkedUpload;
use Muhpuc40\Chunkit\Services\ChunkManager;
use Throwable;

/**
 * Streams the received chunks into the final file.
 *
 * $connection and $queue are left null on purpose: the job then lands on the
 * host app's default queue with no extra setup. ChunkManager only sets them
 * when chunkit.queue_connection / chunkit.queue_name are configured.
 *
 * Note for the host app: the queue connection's retry_after (or
 * visibility_timeout) must be larger than $timeout below, as for any long job.
 */
class MergeChunksJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public $tries = 3;

    public $backoff = [10, 60, 300];

    public $timeout = 600;

    public function __construct(public string $uploadId) {}

    /**
     * @throws Throwable Anything except a final rejection, so the queue retries it.
     */
    public function handle(ChunkManager $manager): void
    {
        try {
            $manager->merge($this->uploadId);
        } catch (ValidationException $e) {
            // The merged file was rejected (wrong type or size). ChunkManager has
            // already recorded the reason and cleaned up. Running the job again
            // cannot change that, so return cleanly and the queue does not retry.
            ChunkedUpload::query()
                ->where('upload_id', $this->uploadId)
                ->where('status', '!=', ChunkedUpload::STATUS_COMPLETED)
                ->update(['status' => ChunkedUpload::STATUS_FAILED]);

            Log::warning('Chunkit: merged file rejected.', [
                'upload_id' => $this->uploadId,
                'errors' => $e->errors(),
            ]);
        }
    }

    /**
     * Safety net for unexpected exceptions, called once the queue has given up
     * after every retry. A rejected file never gets here: handle() deals with it.
     * The row is kept (so the failure stays visible and chunkit:prune cleans it
     * up later), a completed upload is never downgraded, and a generic
     * failure_reason is stored so the UI can say something useful.
     */
    public function failed(Throwable $e): void
    {
        ChunkedUpload::query()
            ->where('upload_id', $this->uploadId)
            ->where('status', '!=', ChunkedUpload::STATUS_COMPLETED)
            ->update([
                'status' => ChunkedUpload::STATUS_FAILED,
                'failure_reason' => 'The merge failed after several attempts. See the application log.',
            ]);

        Log::error('Chunkit: merge job failed.', [
            'upload_id' => $this->uploadId,
            'exception' => $e,
        ]);
    }
}
