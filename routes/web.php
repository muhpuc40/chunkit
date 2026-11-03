<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Muhpuc40\Chunkit\Http\Controllers\ChunkController;

/*
 * Loaded by ChunkitServiceProvider inside a group that applies the configured
 * prefix (chunkit.route_prefix), middleware (chunkit.middleware, default
 * ['web']) and the "chunkit." name prefix. This file is not published.
 *
 * Controller arrays only, never closures, so `php artisan route:cache` works.
 */

Route::post('initiate', [ChunkController::class, 'initiate'])
    ->name('initiate');

Route::post('{uploadId}/chunk', [ChunkController::class, 'chunk'])
    ->whereUuid('uploadId')
    ->name('chunk');

Route::get('{uploadId}/status', [ChunkController::class, 'status'])
    ->whereUuid('uploadId')
    ->name('status');

Route::post('{uploadId}/complete', [ChunkController::class, 'complete'])
    ->whereUuid('uploadId')
    ->name('complete');
