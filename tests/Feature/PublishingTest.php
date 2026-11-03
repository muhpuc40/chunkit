<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Muhpuc40\Chunkit\ChunkitServiceProvider;

/*
 * Publishing writes into the Testbench skeleton, never into a real app. Every
 * file a test publishes is removed again, even when an assertion fails.
 */

it('publishes the config file with the chunkit-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(ChunkitServiceProvider::class, 'chunkit-config');

    expect($paths)->toHaveCount(1);

    $destination = array_values($paths)[0];
    @unlink($destination);

    try {
        $this->artisan('vendor:publish', ['--tag' => 'chunkit-config'])->assertExitCode(0);

        expect(file_exists($destination))->toBeTrue()
            ->and(basename($destination))->toBe('chunkit.php')
            ->and(file_get_contents($destination))->toContain("'queue_connection' => null")
            ->and(file_get_contents($destination))->toContain("'queue_name' => null");
    } finally {
        @unlink($destination);
    }
});

it('publishes the migration with the chunkit-migrations tag, and publishing twice leaves one copy', function () {
    $migrations = fn (): array => glob($this->app->databasePath('migrations/*_create_chunked_uploads_table.php')) ?: [];
    $before = $migrations();

    try {
        $this->artisan('vendor:publish', ['--tag' => 'chunkit-migrations'])->assertExitCode(0);
        $this->artisan('vendor:publish', ['--tag' => 'chunkit-migrations', '--force' => true])->assertExitCode(0);

        $published = array_values(array_diff($migrations(), $before));

        expect($published)->toHaveCount(1)
            ->and(basename($published[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_chunked_uploads_table\.php$/')
            ->and(file_get_contents($published[0]))->toContain("Schema::create('chunked_uploads'");
    } finally {
        foreach (array_diff($migrations(), $before) as $file) {
            @unlink($file);
        }
    }
});

it('does not load the migration automatically: the host app decides when it runs', function () {
    $packageMigrations = realpath(__DIR__.'/../../database/migrations');

    $registered = array_map('realpath', $this->app['migrator']->paths());

    expect($registered)->not->toContain($packageMigrations);
});

it('ships exactly one migration and never one for the queue tables', function () {
    $files = glob(__DIR__.'/../../database/migrations/*.php');

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toBe('create_chunked_uploads_table.php');

    $source = file_get_contents($files[0]);

    foreach (['jobs', 'job_batches', 'failed_jobs'] as $table) {
        expect($source)->not->toContain("Schema::create('{$table}'");
    }
});

it('does not register a schedule entry; the host app adds it', function () {
    $commands = collect($this->app->make(Schedule::class)->events())
        ->map(fn ($event) => (string) ($event->command ?? ''))
        ->filter(fn (string $command) => str_contains($command, 'chunkit'));

    expect($commands)->toBeEmpty();
});

it('does not require horizon or predis', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    foreach (['require', 'require-dev'] as $section) {
        expect($composer[$section])
            ->not->toHaveKey('laravel/horizon')
            ->not->toHaveKey('predis/predis');
    }
});
