<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Muhpuc40\Chunkit\Tests\TestCase;

const STATUS_CHUNK = TestCase::CHUNK_SIZE;

it('reports a fresh upload as pending at offset 0', function () {
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->getJson("/chunkit/{$id}/status")
        ->assertOk()
        ->assertHeader('Upload-Offset', '0')
        ->assertExactJson([
            'upload_id' => $id,
            'received_chunks' => [],
            'offset' => 0,
            'status' => 'pending',
            'chunk_size' => STATUS_CHUNK,
            'total_chunks' => 3,
            'total_bytes' => 2500,
            'failure_reason' => null,
        ]);
});

it('answers "where am I?" with the first gap, not the highest chunk received', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];

    $this->sendChunk($id, $content, STATUS_CHUNK, 0)->assertOk();
    $this->sendChunk($id, $content, STATUS_CHUNK, 2)->assertOk();

    $this->getJson("/chunkit/{$id}/status")
        ->assertOk()
        ->assertHeader('Upload-Offset', (string) STATUS_CHUNK)
        ->assertJson([
            'received_chunks' => [0, 2],
            'offset' => STATUS_CHUNK,
            'status' => 'uploading',
        ]);
});

it('reports the end of the file once every chunk has arrived', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];
    $this->uploadAllChunks($id, $content, STATUS_CHUNK);

    $this->getJson("/chunkit/{$id}/status")
        ->assertOk()
        ->assertJson(['received_chunks' => [0, 1, 2], 'offset' => 2500]);
});

it('only claims what is really on disk', function () {
    $content = $this->pdfContent(2500);
    $id = $this->initiateUpload(2500)['upload_id'];
    $this->uploadAllChunks($id, $content, STATUS_CHUNK);

    // A chunk file disappears behind the database's back.
    Storage::disk('local')->delete("chunkit/{$id}/1");

    $this->getJson("/chunkit/{$id}/status")
        ->assertOk()
        ->assertHeader('Upload-Offset', (string) STATUS_CHUNK)
        ->assertJson(['received_chunks' => [0, 2], 'offset' => STATUS_CHUNK]);

    // The client can now send the missing chunk and carry on.
    $this->sendChunk($id, $content, STATUS_CHUNK, 1)->assertOk()->assertHeader('Upload-Offset', '2500');
});

it('answers 404 with the error shape for an unknown upload', function () {
    $this->getJson('/chunkit/'.Str::uuid().'/status')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Upload not found.']);
});

it('does not route an id that is not a UUID', function () {
    $this->getJson('/chunkit/not-a-uuid/status')->assertNotFound();
});
