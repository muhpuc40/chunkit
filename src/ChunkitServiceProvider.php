<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Muhpuc40\Chunkit\Console\PruneAbandonedUploads;
use Muhpuc40\Chunkit\Contracts\ChunkStorage;
use Muhpuc40\Chunkit\Services\ChunkManager;
use Muhpuc40\Chunkit\Storage\LocalChunkStorage;

class ChunkitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chunkit.php', 'chunkit');

        // v1 stores chunks locally. A future S3/R2 driver only has to implement
        // the ChunkStorage contract and be bound here.
        $this->app->singleton(ChunkStorage::class, function (Application $app): ChunkStorage {
            $config = $app->make('config');

            return new LocalChunkStorage(
                (string) $config->get('chunkit.chunk_disk', 'local'),
                (string) $config->get('chunkit.chunk_directory', 'chunkit'),
            );
        });

        // ChunkManager's constructor dependencies are all resolved by the container.
        $this->app->singleton(ChunkManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/chunkit.php' => $this->app->configPath('chunkit.php'),
            ], 'chunkit-config');

            // Published, never auto-loaded, so the host app decides when it runs.
            $this->publishes([
                __DIR__.'/../database/migrations/create_chunked_uploads_table.php' => $this->migrationTarget(),
            ], 'chunkit-migrations');

            // No schedule is registered here. The README shows the entry to add.
            $this->commands([PruneAbandonedUploads::class]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $config = $this->app->make('config');

        Route::group([
            'prefix' => trim((string) $config->get('chunkit.route_prefix', 'chunkit'), '/'),
            'middleware' => (array) $config->get('chunkit.middleware', ['web']),
            'as' => 'chunkit.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Where `vendor:publish` puts the migration. If an earlier publish already
     * left one in the host app, reuse its path so publishing again never
     * creates a second copy of the same migration.
     */
    private function migrationTarget(): string
    {
        $existing = glob($this->app->databasePath('migrations/*_create_chunked_uploads_table.php')) ?: [];

        return $existing[0]
            ?? $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_chunked_uploads_table.php');
    }
}
