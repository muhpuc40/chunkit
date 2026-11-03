<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Muhpuc40\Chunkit\Contracts\ChunkStorage;
use Muhpuc40\Chunkit\Jobs\MergeChunksJob;
use Muhpuc40\Chunkit\Models\ChunkedUpload;
use Muhpuc40\Chunkit\Services\ChunkManager;
use Muhpuc40\Chunkit\Tests\TestCase;

const JOB_CHUNK = TestCase::CHUNK_SIZE;

it('uses the host default queue and declares the required retry settings', function () {
    $job = new MergeChunksJob(Str::uuid()->toString());

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->connection)->toBeNull()
        ->and($job->queue)->toBeNull()
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300])
        ->and($job->timeout)->toBe(600);
});

it('ships with null queue_connection and queue_name in the default config', function () {
    $defaults = require __DIR__.'/../../config/chunkit.php';

    expect($defaults['queue_connection'])->toBeNull()
        ->and($defaults['queue_name'])->toBeNull();
});

it('streams a 20 MiB upload across 4 chunks without loading the file into memory', function () {
    $chunkSize = 5 * 1024 * 1024;

    config()->set('chunkit.chunk_size', $chunkSize);
    config()->set('chunkit.max_chunk_size', 10 * 1024 * 1024);

    $init = $this->initiateUpload(4 * $chunkSize, 'big.pdf');
    $id = $init['upload_id'];

    expect($init['total_chunks'])->toBe(4);

    // Feed the chunks straight to the manager: pushing 20 MiB through the HTTP
    // test client would only measure the test client.
    $manager = $this->app->make(ChunkManager::class);
    $hash = hash_init('sha256');

    for ($index = 0; $index < 4; $index++) {
        $chunk = $index === 0
            ? "%PDF-1.4\n".random_bytes($chunkSize - 9)
            : random_bytes($chunkSize);

        hash_update($hash, $chunk);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $chunk);
        rewind($stream);
        unset($chunk);

        $manager->storeChunk($id, $index, $index * $chunkSize, $stream);
        fclose($stream);
    }

    $manager->complete($id);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $this->runQueuedMerge($id);

    $growth = memory_get_peak_usage() - $before;

    $row = $this->uploadRow($id);
    $merged = Storage::disk('public')->path($row->destination_path);

    expect($row->status)->toBe('completed')
        ->and(filesize($merged))->toBe(4 * $chunkSize)
        ->and(hash_file('sha256', $merged))->toBe(hash_final($hash));

    // Loading the file would need at least 20 MiB, and even a single chunk is
    // 5 MiB. Streaming only ever holds the 1 MiB used to sniff the file type.
    expect($growth)->toBeLessThan(10 * 1024 * 1024);
});

it('is idempotent: running the job again after completion changes nothing', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, JOB_CHUNK);
    $this->completeUpload($id)->assertStatus(202);

    $this->runQueuedMerge($id);
    $first = hash_file('sha256', Storage::disk('public')->path($this->uploadRow($id)->destination_path));

    $this->runQueuedMerge($id);

    $row = $this->uploadRow($id);

    expect($row->status)->toBe('completed')
        ->and(hash_file('sha256', Storage::disk('public')->path($row->destination_path)))->toBe($first)
        ->and(Storage::disk('local')->exists("chunkit/{$id}"))->toBeFalse();
});

it('steps aside when another worker already holds the merge lock', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, JOB_CHUNK);
    $this->completeUpload($id)->assertStatus(202);

    $lock = Cache::lock("chunkit:{$id}:merge", 60);
    expect($lock->get())->toBeTrue();

    $this->runQueuedMerge($id);

    // Nothing was merged and nothing was lost.
    expect($this->uploadRow($id)->status)->toBe('merging')
        ->and(Storage::disk('public')->exists("uploads/{$id}/lecture.pdf"))->toBeFalse()
        ->and(Storage::disk('local')->exists("chunkit/{$id}/0"))->toBeTrue();

    $lock->release();

    $this->runQueuedMerge($id);

    expect($this->uploadRow($id)->status)->toBe('completed');
});

it('rethrows infrastructure errors so the queue retries, and keeps the row and chunks', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, JOB_CHUNK);
    $this->completeUpload($id)->assertStatus(202);

    $this->mock(ChunkStorage::class, function (MockInterface $mock): void {
        $mock->shouldReceive('assemble')->once()->andThrow(new RuntimeException('disk full'));
    });
    $this->app->forgetInstance(ChunkManager::class);

    $job = new MergeChunksJob($id);

    expect(fn () => $job->handle($this->app->make(ChunkManager::class)))
        ->toThrow(RuntimeException::class, 'disk full');

    // Not deleted, not marked failed yet (retries remain), chunks untouched.
    $row = $this->uploadRow($id);

    expect($row->status)->toBe('merging')
        ->and($row->failure_reason)->toBeNull()
        ->and(Storage::disk('local')->exists("chunkit/{$id}/0"))->toBeTrue()
        ->and(Storage::disk('local')->exists("chunkit/{$id}/1"))->toBeTrue();
});

it('failed() marks the upload failed with a reason and keeps the row and chunks for pruning', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, JOB_CHUNK);
    $this->completeUpload($id)->assertStatus(202);

    (new MergeChunksJob($id))->failed(new RuntimeException('disk full'));

    $row = $this->uploadRow($id);

    expect($row->status)->toBe('failed')
        ->and($row->failure_reason)->not->toBeNull()
        ->and($row->failure_reason)->not->toContain('disk full') // internals stay in the log
        ->and(Storage::disk('local')->exists("chunkit/{$id}/0"))->toBeTrue();

    $this->getJson("/chunkit/{$id}/status")
        ->assertOk()
        ->assertJson(['status' => 'failed', 'failure_reason' => $row->failure_reason]);
});

it('failed() never downgrades an upload that already completed', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, JOB_CHUNK);
    $this->completeUpload($id)->assertStatus(202);
    $this->runQueuedMerge($id);

    (new MergeChunksJob($id))->failed(new RuntimeException('late failure'));

    expect($this->uploadRow($id)->status)->toBe('completed')
        ->and($this->uploadRow($id)->failure_reason)->toBeNull();
});

it('does nothing for an upload that no longer exists', function () {
    $job = new MergeChunksJob(Str::uuid()->toString());

    $job->handle($this->app->make(ChunkManager::class));

    expect(ChunkedUpload::query()->count())->toBe(0);
});
