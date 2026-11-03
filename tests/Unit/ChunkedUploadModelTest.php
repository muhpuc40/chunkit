<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

/**
 * 18 bytes in chunks of 5 = 4 chunks (5, 5, 5, 3).
 *
 * @param  array<int, int>  $received
 */
function makeUpload(array $received = [], string $status = 'pending'): ChunkedUpload
{
    return new ChunkedUpload([
        'total_chunks' => 4,
        'chunk_size' => 5,
        'total_bytes' => 18,
        'received_chunks' => $received,
        'status' => $status,
    ]);
}

it('starts pending with nothing received', function () {
    $upload = new ChunkedUpload(['total_chunks' => 4, 'chunk_size' => 5, 'total_bytes' => 18]);

    expect($upload->status)->toBe('pending')
        ->and($upload->receivedIndexes())->toBe([])
        ->and($upload->offset())->toBe(0)
        ->and($upload->isComplete())->toBeFalse();
});

it('derives the offset from the first gap in the received chunks', function (array $received, int $offset) {
    expect(makeUpload($received)->offset())->toBe($offset);
})->with([
    'nothing received' => [[], 0],
    'the first chunk' => [[0], 5],
    'two in a row' => [[0, 1], 10],
    'a gap at 2' => [[0, 1, 3], 10],
    'only a later chunk' => [[3], 0],
    'out of order, no gap' => [[2, 0, 1], 15],
    'everything: capped at the file size' => [[0, 1, 2, 3], 18],
]);

it('counts contiguous chunks from index 0', function () {
    expect(makeUpload([0, 1, 3])->contiguousChunkCount())->toBe(2)
        ->and(makeUpload([1, 2, 3])->contiguousChunkCount())->toBe(0);
});

it('returns received indexes unique, in range and sorted', function () {
    expect(makeUpload([3, 1, 0, 1, 1, 99, -1])->receivedIndexes())->toBe([0, 1, 3]);
});

it('knows when every chunk has arrived', function () {
    expect(makeUpload([0, 1, 2])->isComplete())->toBeFalse()
        ->and(makeUpload([3, 2, 1, 0])->isComplete())->toBeTrue()
        ->and(makeUpload([0, 1, 2, 3])->hasChunk(2))->toBeTrue()
        ->and(makeUpload([0, 1])->hasChunk(2))->toBeFalse();
});

it('gives each chunk its start byte', function () {
    $upload = makeUpload();

    expect($upload->chunkOffset(0))->toBe(0)
        ->and($upload->chunkOffset(2))->toBe(10)
        ->and($upload->chunkOffset(3))->toBe(15);
});

it('expects every chunk to be chunk_size bytes except the last, which carries the remainder', function () {
    $upload = makeUpload();

    expect($upload->expectedChunkSize(0))->toBe(5)
        ->and($upload->expectedChunkSize(2))->toBe(5)
        ->and($upload->expectedChunkSize(3))->toBe(3);
});

it('gives the last chunk full size when the file is an exact multiple of chunk_size', function () {
    $upload = new ChunkedUpload(['total_chunks' => 2, 'chunk_size' => 5, 'total_bytes' => 10]);

    expect($upload->expectedChunkSize(1))->toBe(5);
});

it('refuses to describe a chunk that is not part of the upload', function (int $index) {
    expect(fn () => makeUpload()->chunkOffset($index))->toThrow(OutOfRangeException::class)
        ->and(fn () => makeUpload()->expectedChunkSize($index))->toThrow(OutOfRangeException::class);
})->with([-1, 4, 100]);

it('accepts chunks only before the merge starts', function (string $status, bool $accepts) {
    expect(makeUpload([], $status)->acceptsChunks())->toBe($accepts);
})->with([
    'pending' => ['pending', true],
    'uploading' => ['uploading', true],
    'merging' => ['merging', false],
    'completed' => ['completed', false],
    'failed' => ['failed', false],
]);

it('stores received chunks as JSON and reads them back as an array', function () {
    $row = ChunkedUpload::query()->create([
        'upload_id' => Str::uuid()->toString(),
        'original_name' => 'a.pdf',
        'total_chunks' => 4,
        'chunk_size' => 5,
        'total_bytes' => 18,
        'received_chunks' => [0, 2],
        'expires_at' => now()->addDay(),
    ]);

    $fresh = ChunkedUpload::query()->findOrFail($row->id);

    expect($fresh->received_chunks)->toBe([0, 2])
        ->and($fresh->status)->toBe('pending')
        ->and($fresh->failure_reason)->toBeNull();
});

it('scopes abandoned uploads to unfinished ones past their expiry', function () {
    $make = fn (string $status, $expires) => ChunkedUpload::query()->create([
        'upload_id' => Str::uuid()->toString(),
        'original_name' => "{$status}.pdf",
        'total_chunks' => 1,
        'chunk_size' => 5,
        'total_bytes' => 5,
        'received_chunks' => [],
        'status' => $status,
        'expires_at' => $expires,
    ]);

    $make('uploading', now()->subHour());
    $make('failed', now()->subHour());
    $make('merging', now()->subHour());
    $make('completed', now()->subHour());   // never abandoned: the row records the file
    $make('uploading', now()->addHour());   // still fresh

    $names = ChunkedUpload::query()->abandoned()->orderBy('id')->pluck('original_name')->all();

    expect($names)->toBe(['uploading.pdf', 'failed.pdf', 'merging.pdf']);
});

it('has the five documented statuses', function () {
    expect(ChunkedUpload::STATUSES)->toBe(['pending', 'uploading', 'merging', 'completed', 'failed']);
});
