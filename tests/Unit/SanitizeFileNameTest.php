<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Muhpuc40\Chunkit\Services\ChunkManager;

it('turns a client file name into a safe one', function (string $input, string $expected) {
    expect(app(ChunkManager::class)->sanitizeFileName($input))->toBe($expected);
})->with([
    'plain name' => ['lecture.pdf', 'lecture.pdf'],
    'parent directories dropped' => ['../../etc/passwd.mp4', 'passwd.mp4'],
    'windows path dropped' => ['C:\\Users\\me\\c.PDF', 'c.pdf'],
    'extension lowercased' => ['Movie.MP4', 'Movie.mp4'],
    'whitespace collapsed' => ["My   Lecture \t 01.MP4", 'My Lecture 01.mp4'],
    'control characters removed' => ["bad\x00na\x07me.mp4", 'badname.mp4'],
    'windows-reserved characters replaced' => ['a:b*c?.png', 'a_b_c_.png'],
    'reserved device name prefixed' => ['con.mp4', '_con.mp4'],
    'double extension kept' => ['archive.tar.gz', 'archive.tar.gz'],
    'unicode kept' => ['পাঠ ১.mp4', 'পাঠ ১.mp4'],
    'a dot that is not an extension' => ['Dr. Smith report', 'Dr. Smith report'],
    'leading dot dropped' => ['.hidden', 'hidden'],
    'trailing dots and spaces trimmed' => ['report.pdf. . ', 'report.pdf'],
]);

it('refuses blocked extensions anywhere in the name', function (string $input) {
    expect(fn () => app(ChunkManager::class)->sanitizeFileName($input))
        ->toThrow(ValidationException::class);
})->with([
    'script' => ['evil.php'],
    'uppercase' => ['EVIL.PHP'],
    'mixed case' => ['Shell.PhP'],
    'hidden behind an innocent extension' => ['shell.php.jpg'],
    'executable' => ['setup.exe'],
    'phar' => ['x.phar'],
    'trailing dot and space' => ['x.php. '],
    'right-to-left override trick' => ["report\u{202E}gpj.exe"],
]);

it('reports a blocked extension against the original_name field', function () {
    try {
        app(ChunkManager::class)->sanitizeFileName('evil.php');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('original_name');

        return;
    }

    $this->fail('A blocked extension was accepted.');
});

it('refuses names with nothing usable in them', function (string $input) {
    expect(fn () => app(ChunkManager::class)->sanitizeFileName($input))
        ->toThrow(ValidationException::class);
})->with([
    'empty' => [''],
    'only dots and spaces' => ['  ... '],
    'only a directory' => ['folder/'],
    'invalid UTF-8' => ["a\xC3\x28.mp4"],
]);

it('caps long names at 255 bytes and keeps the extension', function () {
    $ascii = app(ChunkManager::class)->sanitizeFileName(str_repeat('a', 400).'.mp4');
    $bengali = app(ChunkManager::class)->sanitizeFileName(str_repeat('ক', 200).'.mp4');

    expect(strlen($ascii))->toBeLessThanOrEqual(255)
        ->and($ascii)->toEndWith('.mp4')
        ->and(strlen($bengali))->toBeLessThanOrEqual(255)
        ->and($bengali)->toEndWith('.mp4')
        ->and(mb_check_encoding($bengali, 'UTF-8'))->toBeTrue();
});

it('follows the blocked_extensions config', function () {
    config()->set('chunkit.blocked_extensions', ['mp4']);

    expect(app(ChunkManager::class)->sanitizeFileName('evil.php'))->toBe('evil.php');

    expect(fn () => app(ChunkManager::class)->sanitizeFileName('movie.mp4'))
        ->toThrow(ValidationException::class);
});
