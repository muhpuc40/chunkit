<?php

declare(strict_types=1);

use Muhpuc40\Chunkit\Services\ChunkManager;

it('matches exact types and family wildcards', function (string $mime, array $allowed, bool $expected) {
    expect(ChunkManager::mimeIsAllowed($mime, $allowed))->toBe($expected);
})->with([
    'exact match' => ['video/mp4', ['video/mp4'], true],
    'exact match among several' => ['application/pdf', ['video/mp4', 'application/pdf'], true],
    'family wildcard' => ['video/webm', ['video/*'], true],
    'wildcard does not cross families' => ['application/pdf', ['video/*'], false],
    'no match' => ['text/html', ['video/mp4', 'application/pdf'], false],
    'a similar prefix is not the same type' => ['video/mp4x', ['video/mp4'], false],
    'case is ignored' => ['Video/MP4', ['video/mp4'], true],
    'parameters are ignored' => ['video/mp4; codecs=avc1', ['video/mp4'], true],
    'surrounding spaces are ignored' => ['  video/mp4  ', [' video/mp4 '], true],
    'an empty allow list allows nothing' => ['video/mp4', [], false],
    'an empty type is never allowed' => ['', ['video/*'], false],
    'a bare wildcard family needs the slash' => ['videos/mp4', ['video/*'], false],
]);

it('allows the types the default config lists, including both rar spellings', function () {
    $allowed = config('chunkit.allowed_mimes');

    foreach (['video/mp4', 'application/pdf', 'application/zip', 'image/png', 'application/x-rar', 'application/vnd.rar'] as $mime) {
        expect(ChunkManager::mimeIsAllowed($mime, $allowed))->toBeTrue("{$mime} should be allowed");
    }

    foreach (['text/html', 'text/x-php', 'application/x-httpd-php', 'application/x-dosexec', 'image/svg+xml'] as $mime) {
        expect(ChunkManager::mimeIsAllowed($mime, $allowed))->toBeFalse("{$mime} should be refused");
    }
});
