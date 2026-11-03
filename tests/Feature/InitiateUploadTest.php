<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Muhpuc40\Chunkit\Tests\TestCase;

it('creates an upload and returns its id, the server chunk size and the chunk count', function () {
    $response = $this->postJson('/chunkit/initiate', [
        'original_name' => 'lecture.pdf',
        'total_bytes' => 2500,
        'mime_type' => 'application/pdf',
    ]);

    $response
        ->assertCreated()
        ->assertJsonStructure(['upload_id', 'chunk_size', 'total_chunks'])
        ->assertJson(['chunk_size' => TestCase::CHUNK_SIZE, 'total_chunks' => 3]);

    expect(Str::isUuid($response->json('upload_id')))->toBeTrue();
});

it('always takes the chunk size from config, never from the client', function () {
    $response = $this->postJson('/chunkit/initiate', [
        'original_name' => 'lecture.pdf',
        'total_bytes' => 5000,
        'chunk_size' => 1,
        'total_chunks' => 1,
    ]);

    $response->assertCreated()->assertJson([
        'chunk_size' => TestCase::CHUNK_SIZE,
        'total_chunks' => 5,
    ]);
});

it('stores the upload as a pending row that expires after the configured lifetime', function () {
    $init = $this->initiateUpload(2500);

    $row = $this->uploadRow($init['upload_id']);

    expect($row->status)->toBe('pending')
        ->and($row->original_name)->toBe('lecture.pdf')
        ->and($row->total_chunks)->toBe(3)
        ->and($row->chunk_size)->toBe(TestCase::CHUNK_SIZE)
        ->and($row->total_bytes)->toBe(2500)
        ->and($row->receivedIndexes())->toBe([])
        ->and($row->destination_path)->toBeNull()
        ->and($row->failure_reason)->toBeNull()
        ->and($row->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
});

it('accepts an upload that declares no mime type', function () {
    $this->initiateUpload(100, 'notes.pdf', null);

    expect(true)->toBeTrue();
});

it('sanitises the file name before storing it', function () {
    $init = $this->initiateUpload(100, '../../My   Notes.PDF');

    expect($this->uploadRow($init['upload_id'])->original_name)->toBe('My Notes.pdf');
});

it('never queues anything when an upload is initiated', function () {
    $this->initiateUpload(100);

    Queue::assertNothingPushed();
});

it('rejects blocked extensions, including a hidden double extension', function (string $name) {
    $this->postJson('/chunkit/initiate', ['original_name' => $name, 'total_bytes' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['original_name']);
})->with(['evil.php', 'EVIL.PHP', 'shell.php.jpg', 'run.exe']);

it('rejects a declared mime type that is not allowed', function () {
    $this->postJson('/chunkit/initiate', [
        'original_name' => 'page.pdf',
        'total_bytes' => 100,
        'mime_type' => 'text/html',
    ])->assertStatus(422)->assertJsonValidationErrors(['mime_type']);
});

it('answers invalid input with the package JSON error shape', function (array $payload, string $field) {
    $this->postJson('/chunkit/initiate', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => [$field]]);
})->with([
    'missing name' => [['total_bytes' => 10], 'original_name'],
    'missing size' => [['original_name' => 'a.pdf'], 'total_bytes'],
    'zero bytes' => [['original_name' => 'a.pdf', 'total_bytes' => 0], 'total_bytes'],
    'not a number' => [['original_name' => 'a.pdf', 'total_bytes' => 'abc'], 'total_bytes'],
    'over the max total size' => [['original_name' => 'a.pdf', 'total_bytes' => 5368709121], 'total_bytes'],
]);

it('answers with JSON even when the client sent no Accept header', function () {
    $this->post('/chunkit/initiate', [])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});
