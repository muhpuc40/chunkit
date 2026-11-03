<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Muhpuc40\Chunkit\Http\Controllers\ChunkController;

it('registers the four endpoints under the default prefix with the web middleware', function (string $name, string $method, string $uri, string $action) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe($uri)
        ->and($route->methods())->toContain($method)
        ->and($route->gatherMiddleware())->toContain('web')
        ->and($route->gatherMiddleware())->not->toContain('api')
        ->and($route->getActionName())->toBe(ChunkController::class.'@'.$action);
})->with([
    'initiate' => ['chunkit.initiate', 'POST', 'chunkit/initiate', 'initiate'],
    'chunk' => ['chunkit.chunk', 'POST', 'chunkit/{uploadId}/chunk', 'chunk'],
    'status' => ['chunkit.status', 'GET', 'chunkit/{uploadId}/status', 'status'],
    'complete' => ['chunkit.complete', 'POST', 'chunkit/{uploadId}/complete', 'complete'],
]);

it('registers nothing else under the chunkit name prefix', function () {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'chunkit.'))
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe(['chunkit.chunk', 'chunkit.complete', 'chunkit.initiate', 'chunkit.status']);
});

it('only uses controller actions, so route:cache keeps working', function () {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'chunkit.')) {
            continue;
        }

        expect($route->getAction('uses'))->toBeString();
    }
});

it('only matches upload ids that are UUIDs', function (string $uri) {
    $this->getJson($uri)->assertNotFound();
})->with([
    '/chunkit/not-a-uuid/status',
    '/chunkit/123/status',
    '/chunkit/../status',
]);

it('exposes the artisan command', function () {
    expect(array_keys(Artisan::all()))->toContain('chunkit:prune');
});
