<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Muhpuc40\Chunkit\Storage\LocalChunkStorage;

/**
 * @return resource
 */
function chunkStream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('stores, reports and lists chunks', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();

    foreach ([2 => 'cc', 0 => 'aaaa', 1 => 'bbb'] as $index => $bytes) {
        expect($storage->putChunk($id, $index, chunkStream($bytes)))->toBe(strlen($bytes));
    }

    expect($storage->listChunks($id))->toBe([0, 1, 2])
        ->and($storage->hasChunk($id, 1))->toBeTrue()
        ->and($storage->hasChunk($id, 9))->toBeFalse()
        ->and($storage->chunkSize($id, 0))->toBe(4)
        ->and(Storage::disk('local')->exists("chunkit/{$id}/2"))->toBeTrue();

    $read = $storage->readChunkStream($id, 1);
    expect(stream_get_contents($read))->toBe('bbb');
    fclose($read);
});

it('overwrites a chunk when the same index is stored again', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();

    $storage->putChunk($id, 0, chunkStream('first'));
    $storage->putChunk($id, 0, chunkStream('second!'));

    expect($storage->chunkSize($id, 0))->toBe(7)
        ->and($storage->listChunks($id))->toBe([0]);
});

it('ignores stray files that are not chunks when listing', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();

    $storage->putChunk($id, 0, chunkStream('a'));
    Storage::disk('local')->put("chunkit/{$id}/notes.tmp", 'x');

    expect($storage->listChunks($id))->toBe([0]);
});

it('assembles chunks in order into one file, and a retried assemble replaces it', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();
    $parts = [random_bytes(700), random_bytes(700), random_bytes(300)];

    foreach ([2, 0, 1] as $index) {
        $storage->putChunk($id, $index, chunkStream($parts[$index]));
    }

    $written = $storage->assemble($id, 3, 'public', "uploads/{$id}/out.bin");
    $path = Storage::disk('public')->path("uploads/{$id}/out.bin");

    expect($written)->toBe(1700)
        ->and(hash_file('sha256', $path))->toBe(hash('sha256', implode('', $parts)))
        ->and(glob(dirname($path).'/*.part'))->toBe([]);

    // Running it again (a retried merge) gives the same file.
    expect($storage->assemble($id, 3, 'public', "uploads/{$id}/out.bin"))->toBe(1700)
        ->and(hash_file('sha256', $path))->toBe(hash('sha256', implode('', $parts)));
});

it('refuses to assemble with a chunk missing and leaves an earlier file untouched', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();

    $storage->putChunk($id, 0, chunkStream('aaa'));
    $storage->putChunk($id, 1, chunkStream('bbb'));
    $storage->assemble($id, 2, 'public', "uploads/{$id}/out.bin");

    Storage::disk('local')->delete("chunkit/{$id}/1");

    expect(fn () => $storage->assemble($id, 2, 'public', "uploads/{$id}/out.bin"))
        ->toThrow(RuntimeException::class, 'chunk 1 is missing')
        ->and(Storage::disk('public')->get("uploads/{$id}/out.bin"))->toBe('aaabbb');
});

it('deletes every chunk and the directory, and deleting twice is fine', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();

    $storage->putChunk($id, 0, chunkStream('a'));
    $storage->putChunk($id, 1, chunkStream('b'));

    $storage->deleteUpload($id);
    $storage->deleteUpload($id);

    expect(Storage::disk('local')->exists("chunkit/{$id}"))->toBeFalse()
        ->and($storage->listChunks($id))->toBe([]);
});

it('only accepts real UUIDs as upload ids and never lets a path escape', function () {
    $storage = new LocalChunkStorage('local', 'chunkit');
    $id = Str::uuid()->toString();

    expect(fn () => $storage->hasChunk('../etc', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->hasChunk($id, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->assemble($id, 1, 'public', '../evil.txt'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->assemble($id, 1, 'public', '/etc/passwd'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->assemble($id, 1, 'public', 'C:\\x\\y'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->assemble($id, 0, 'public', 'a/b.bin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LocalChunkStorage('local', '../outside'))->toThrow(InvalidArgumentException::class);
});

it('fails fast when the chunk disk or the final disk is not a local-driver disk', function () {
    config()->set('filesystems.disks.cloud', ['driver' => 's3', 'bucket' => 'b', 'region' => 'r', 'key' => 'k', 'secret' => 's']);

    $id = Str::uuid()->toString();

    expect(fn () => (new LocalChunkStorage('cloud'))->hasChunk($id, 0))
        ->toThrow(RuntimeException::class, '[cloud]');

    $storage = new LocalChunkStorage('local');
    $storage->putChunk($id, 0, chunkStream('a'));

    expect(fn () => $storage->assemble($id, 1, 'cloud', 'a/b.bin'))
        ->toThrow(RuntimeException::class, '[cloud]');
});
