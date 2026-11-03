<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

it('deletes expired uploads, both the row and the chunk directory, and keeps fresh ones', function () {
    $expired = $this->startUploadWithChunk('old.pdf');
    $fresh = $this->startUploadWithChunk('new.pdf');
    $this->expireUpload($expired);

    expect(Storage::disk('local')->exists("chunkit/{$expired}/0"))->toBeTrue();

    $this->artisan('chunkit:prune')
        ->expectsOutputToContain('Deleted 1 abandoned upload(s).')
        ->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $expired)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists("chunkit/{$expired}"))->toBeFalse()
        ->and(ChunkedUpload::query()->where('upload_id', $fresh)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists("chunkit/{$fresh}/0"))->toBeTrue();
});

it('prunes an expired upload that has no chunks yet', function () {
    $id = $this->initiateUpload(2500)['upload_id'];
    $this->expireUpload($id);

    $this->artisan('chunkit:prune')->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $id)->exists())->toBeFalse();
});

it('prunes failed and stuck uploads once they expire, but never a completed one', function () {
    $failed = $this->startUploadWithChunk('failed.pdf');
    ChunkedUpload::query()->where('upload_id', $failed)->update(['status' => 'failed']);

    $completed = $this->initiateUpload(2500, 'done.pdf')['upload_id'];
    ChunkedUpload::query()->where('upload_id', $completed)->update([
        'status' => 'completed',
        'destination_path' => "uploads/{$completed}/done.pdf",
    ]);

    $this->expireUpload($failed);
    $this->expireUpload($completed);

    $this->artisan('chunkit:prune')->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $failed)->exists())->toBeFalse()
        ->and(ChunkedUpload::query()->where('upload_id', $completed)->exists())->toBeTrue();
});

it('reports what it would delete without deleting anything on a dry run', function () {
    $expired = $this->startUploadWithChunk();
    $this->expireUpload($expired);

    $this->artisan('chunkit:prune', ['--dry-run' => true])
        ->expectsOutputToContain('would be deleted')
        ->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $expired)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists("chunkit/{$expired}/0"))->toBeTrue();
});

it('leaves an upload alone while its merge is running', function () {
    $id = $this->startUploadWithChunk();
    $this->expireUpload($id);

    $mergeLock = Cache::lock("chunkit:{$id}:merge", 60);
    expect($mergeLock->get())->toBeTrue();

    $this->artisan('chunkit:prune')->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists("chunkit/{$id}/0"))->toBeTrue();

    $mergeLock->release();

    $this->artisan('chunkit:prune')->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $id)->exists())->toBeFalse();
});

it('honours ttl_hours when it sets the expiry', function () {
    config()->set('chunkit.ttl_hours', 2);

    $id = $this->startUploadWithChunk();
    $row = $this->uploadRow($id);

    expect($row->expires_at->between(now()->addHour(), now()->addHours(3)))->toBeTrue();
});

it('succeeds when there is nothing to prune', function () {
    $this->artisan('chunkit:prune')
        ->expectsOutputToContain('Deleted 0 abandoned upload(s).')
        ->assertExitCode(0);
});
