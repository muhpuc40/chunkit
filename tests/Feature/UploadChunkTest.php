<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Muhpuc40\Chunkit\Tests\TestCase;

const CHUNK = TestCase::CHUNK_SIZE;

it('stores a chunk and reports the server offset in the body and the header', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->sendChunk($id, $content, CHUNK, 0)
        ->assertOk()
        ->assertHeader('Upload-Offset', (string) CHUNK)
        ->assertJson([
            'upload_id' => $id,
            'offset' => CHUNK,
            'status' => 'uploading',
            'received_count' => 1,
            'total_chunks' => 3,
        ]);

    expect(Storage::disk('local')->get("chunkit/{$id}/0"))->toBe(substr($content, 0, CHUNK))
        ->and($this->uploadRow($id)->receivedIndexes())->toBe([0]);
});

it('accepts chunks out of order and only advances the offset once the gap is filled', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    // Last chunk first: stored, but there is still a gap at the start.
    $this->sendChunk($id, $content, CHUNK, 2)->assertOk()->assertHeader('Upload-Offset', '0');
    $this->sendChunk($id, $content, CHUNK, 0)->assertOk()->assertHeader('Upload-Offset', (string) CHUNK);

    // Filling the last gap moves the resume point to the end of the file.
    $this->sendChunk($id, $content, CHUNK, 1)->assertOk()->assertHeader('Upload-Offset', '2500');

    expect($this->uploadRow($id)->receivedIndexes())->toBe([0, 1, 2]);
});

it('treats a duplicate chunk request as a no-op and never corrupts the stored chunk', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $first = $this->sendChunk($id, $content, CHUNK, 0)->assertOk();
    $second = $this->sendChunk($id, $content, CHUNK, 0)->assertOk();

    // Same index and offset again, but different bytes (a confused retry).
    $third = $this->sendChunk($id, str_repeat('Z', 2500), CHUNK, 0)->assertOk();

    expect($second->json())->toBe($first->json())
        ->and($third->json('offset'))->toBe(CHUNK)
        ->and($this->uploadRow($id)->receivedIndexes())->toBe([0])
        ->and(Storage::disk('local')->get("chunkit/{$id}/0"))->toBe(substr($content, 0, CHUNK));
});

it('answers a wrong Upload-Offset with 409 and the first gap as the real resume point', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->sendChunk($id, $content, CHUNK, 0)->assertOk();

    // Chunk 2 would start at byte 2048, but the client claims 0. The server has
    // chunk 0 and is missing chunk 1, so its real resume point is byte 1024.
    // That is the first gap, not the rejected chunk's own start (2048).
    $this->sendChunk($id, $content, CHUNK, 2, offset: 0)
        ->assertStatus(409)
        ->assertHeader('Upload-Offset', (string) CHUNK)
        ->assertJson(['offset' => CHUNK])
        ->assertJsonStructure(['message', 'offset']);

    // The rejected chunk was not stored.
    expect($this->uploadRow($id)->receivedIndexes())->toBe([0]);
});

it('answers 409 with offset 0 when nothing has been received yet', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->sendChunk($id, $content, CHUNK, 1, offset: 999)
        ->assertStatus(409)
        ->assertHeader('Upload-Offset', '0');
});

it('requires the Upload-Offset header', function () {
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->post("/chunkit/{$id}/chunk", [
        'index' => 0,
        'chunk' => UploadedFile::fake()->createWithContent('c.bin', str_repeat('a', CHUNK)),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['upload_offset']);
});

it('requires the chunk file', function () {
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->post("/chunkit/{$id}/chunk", ['index' => 0], [
        'Accept' => 'application/json',
        'Upload-Offset' => '0',
    ])->assertStatus(422)->assertJsonValidationErrors(['chunk']);
});

it('rejects a chunk index outside the upload', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->sendChunk($id, $content, CHUNK, 99, offset: 0)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['index']);
});

it('rejects a chunk that is not exactly the expected size', function (int $index, int $bytes) {
    $id = $this->initiateUpload(2500)['upload_id'];

    // The offset is correct for the index, so only the size check can fail.
    $this->post("/chunkit/{$id}/chunk", [
        'index' => $index,
        'chunk' => UploadedFile::fake()->createWithContent('c.bin', str_repeat('a', $bytes)),
    ], [
        'Accept' => 'application/json',
        'Upload-Offset' => (string) ($index * CHUNK),
    ])->assertStatus(422)->assertJsonValidationErrors(['chunk']);

    expect($this->uploadRow($id)->receivedIndexes())->toBe([]);
})->with([
    'a short first chunk' => [0, 10],
    'an oversized middle chunk' => [1, 2048],
    'a last chunk that is too big (it must be 452 bytes)' => [2, CHUNK],
    'a last chunk that is too small' => [2, 451],
]);

it('answers 404 for an unknown upload', function () {
    $content = $this->pdfContent(CHUNK);

    $this->sendChunk(Str::uuid()->toString(), $content, CHUNK, 0)
        ->assertNotFound()
        ->assertJson(['message' => 'Upload not found.']);
});

it('answers 410 for an expired upload', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];
    $this->expireUpload($id);

    $this->sendChunk($id, $content, CHUNK, 0)->assertStatus(410)->assertJsonStructure(['message']);
});

it('refuses chunks once the merge has been queued', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];
    $this->uploadAllChunks($id, $content, CHUNK);
    $this->completeUpload($id)->assertStatus(202);

    $this->sendChunk($id, $content, CHUNK, 0)
        ->assertStatus(409)
        ->assertHeader('Upload-Offset', '2500');
});

it('answers 409 with the resume point when another request holds the upload lock', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];
    $this->sendChunk($id, $content, CHUNK, 0)->assertOk();

    config()->set('chunkit.lock_wait_seconds', 0);

    $held = Cache::lock("chunkit:{$id}", 30);
    expect($held->get())->toBeTrue();

    $this->sendChunk($id, $content, CHUNK, 1)
        ->assertStatus(409)
        ->assertHeader('Upload-Offset', (string) CHUNK);

    $held->release();

    // Once the other request is done the same chunk goes through.
    $this->sendChunk($id, $content, CHUNK, 1)->assertOk();
});

it('releases the upload lock after every request, successful or not', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->sendChunk($id, $content, CHUNK, 0)->assertOk();
    $this->sendChunk($id, $content, CHUNK, 2, offset: 5)->assertStatus(409);

    $lock = Cache::lock("chunkit:{$id}", 5);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
