<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Muhpuc40\Chunkit\Jobs\MergeChunksJob;
use Muhpuc40\Chunkit\Tests\TestCase;

const COMPLETE_CHUNK = TestCase::CHUNK_SIZE;

it('queues the merge, then merges the chunks into a file whose sha256 matches', function () {
    $content = $this->pdfContent(3000); // chunks of 1024, 1024 and 952 bytes
    $id = $this->initiateUpload(3000)['upload_id'];

    $chunkHashes = hash_init('sha256');

    foreach ([0, 1, 2] as $index) {
        $this->sendChunk($id, $content, COMPLETE_CHUNK, $index)->assertOk();
        hash_update($chunkHashes, substr($content, $index * COMPLETE_CHUNK, COMPLETE_CHUNK));
    }

    $this->completeUpload($id)
        ->assertStatus(202)
        ->assertJson(['upload_id' => $id, 'status' => 'merging']);

    Queue::assertPushed(MergeChunksJob::class, 1);

    // The HTTP request never merges: nothing has been published yet.
    expect(Storage::disk('public')->exists("uploads/{$id}/lecture.pdf"))->toBeFalse();

    $this->runQueuedMerge($id);

    $row = $this->uploadRow($id);

    expect($row->status)->toBe('completed')
        ->and($row->destination_path)->toBe("uploads/{$id}/lecture.pdf")
        ->and($row->failure_reason)->toBeNull();

    $merged = Storage::disk('public')->path($row->destination_path);

    expect(filesize($merged))->toBe(3000)
        ->and(hash_file('sha256', $merged))->toBe(hash_final($chunkHashes))
        ->and(hash_file('sha256', $merged))->toBe(hash('sha256', $content));

    // The chunks are cleaned up once the file is published.
    expect(Storage::disk('local')->exists("chunkit/{$id}"))->toBeFalse();

    $this->getJson("/chunkit/{$id}/status")->assertOk()->assertJson(['status' => 'completed']);
});

it('merges chunks that arrived out of order into the correct file', function () {
    $content = $this->pdfContent(3000);
    $id = $this->initiateUpload(3000)['upload_id'];

    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK, [2, 0, 1]);
    $this->completeUpload($id)->assertStatus(202);
    $this->runQueuedMerge($id);

    $merged = Storage::disk('public')->path($this->uploadRow($id)->destination_path);

    expect(hash_file('sha256', $merged))->toBe(hash('sha256', $content));
});

it('answers 422 when the upload is incomplete and queues nothing', function () {
    $content = $this->pdfContent(3000);
    $id = $this->initiateUpload(3000)['upload_id'];
    $this->sendChunk($id, $content, COMPLETE_CHUNK, 0)->assertOk();

    $response = $this->completeUpload($id)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['upload']]);

    expect($response->json('message'))->toContain('1 of 3');

    Queue::assertNothingPushed();
    expect($this->uploadRow($id)->status)->toBe('uploading');
});

it('does not queue a second merge when complete is called again', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK);

    $this->completeUpload($id)->assertStatus(202);
    $this->completeUpload($id)->assertStatus(202)->assertJson(['status' => 'merging']);

    Queue::assertPushed(MergeChunksJob::class, 1);

    $this->runQueuedMerge($id);

    // Once finished, complete reports it with 200 instead of 202.
    $this->completeUpload($id)->assertOk()->assertJson(['status' => 'completed']);
    Queue::assertPushed(MergeChunksJob::class, 1);
});

it('queues the merge on the host default queue unless one is configured', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK);

    $this->completeUpload($id)->assertStatus(202);

    Queue::assertPushed(
        MergeChunksJob::class,
        fn (MergeChunksJob $job): bool => $job->connection === null && $job->queue === null,
    );
});

it('applies queue_connection and queue_name when they are configured', function () {
    config()->set('chunkit.queue_connection', 'redis');
    config()->set('chunkit.queue_name', 'uploads');

    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK);

    $this->completeUpload($id)->assertStatus(202);

    Queue::assertPushed(
        MergeChunksJob::class,
        fn (MergeChunksJob $job): bool => $job->connection === 'redis' && $job->queue === 'uploads',
    );
});

it('rejects a merged file whose real content is not an allowed type', function (string $name, string $declared, string $content) {
    $id = $this->initiateUpload(strlen($content), $name, $declared)['upload_id'];
    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK);
    $this->completeUpload($id)->assertStatus(202);

    // The job returns cleanly: a rejection can never succeed, so it is not retried.
    $this->runQueuedMerge($id);

    $row = $this->uploadRow($id);

    // The row is kept so the failure stays visible, with the reason recorded.
    expect($row->status)->toBe('failed')
        ->and($row->destination_path)->toBeNull()
        ->and($row->failure_reason)->toBe('The content of the file is not an allowed type.');

    // The assembled file and the chunks are both gone.
    expect(Storage::disk('public')->exists("uploads/{$id}/{$name}"))->toBeFalse()
        ->and(Storage::disk('local')->exists("chunkit/{$id}"))->toBeFalse();

    $this->getJson("/chunkit/{$id}/status")
        ->assertOk()
        ->assertJson([
            'status' => 'failed',
            'failure_reason' => 'The content of the file is not an allowed type.',
        ]);

    // A rejected upload cannot be pushed through again.
    $this->completeUpload($id)->assertStatus(422);
})->with([
    'a PHP script named trojan.png, declared image/png' => [
        'trojan.png',
        'image/png',
        '<?php system($_GET["c"]); ?>'.str_repeat(' ', 2000),
    ],
    'a Windows executable named movie.mp4, declared video/mp4' => [
        'movie.mp4',
        'video/mp4',
        "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 2000),
    ],
]);

it('answers 404 when completing an unknown upload', function () {
    $this->completeUpload(Str::uuid()->toString())
        ->assertNotFound()
        ->assertJson(['message' => 'Upload not found.']);
});

it('answers 410 when completing an expired upload', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK);
    $this->expireUpload($id);

    $this->completeUpload($id)->assertStatus(410);
    Queue::assertNothingPushed();
});

it('drops chunks that vanished from disk instead of merging a broken file', function () {
    $content = $this->pdfContent(2000);
    $id = $this->initiateUpload(2000)['upload_id'];
    $this->uploadAllChunks($id, $content, COMPLETE_CHUNK);

    Storage::disk('local')->delete("chunkit/{$id}/1");

    $this->completeUpload($id)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['upload']]);

    expect($this->uploadRow($id)->receivedIndexes())->toBe([0]);
    Queue::assertNothingPushed();
});
