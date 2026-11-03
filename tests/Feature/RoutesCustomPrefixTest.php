<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Muhpuc40\Chunkit\Tests\TestCase;

/**
 * The prefix and the middleware must be read from config when the application
 * boots, so this case sets them before boot. That needs a class-based test:
 * a Pest closure only runs after the application already exists.
 */
final class RoutesCustomPrefixTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Slashes around the prefix are tolerated and trimmed.
        $app['config']->set('chunkit.route_prefix', '/files/upload/');
        $app['config']->set('chunkit.middleware', ['web', 'auth']);
    }

    public function test_routes_use_the_configured_prefix_and_middleware(): void
    {
        $route = Route::getRoutes()->getByName('chunkit.initiate');

        $this->assertNotNull($route);
        $this->assertSame('files/upload/initiate', $route->uri());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());

        $status = Route::getRoutes()->getByName('chunkit.status');

        $this->assertNotNull($status);
        $this->assertSame('files/upload/{uploadId}/status', $status->uri());
    }

    public function test_the_default_prefix_is_no_longer_routed(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));

        $this->postJson('/chunkit/initiate', ['original_name' => 'a.pdf', 'total_bytes' => 10])
            ->assertNotFound();
    }

    public function test_the_configured_middleware_is_enforced(): void
    {
        $this->postJson('/files/upload/initiate', ['original_name' => 'a.pdf', 'total_bytes' => 10])
            ->assertUnauthorized();

        $this->actingAs(new GenericUser(['id' => 1]));

        $this->postJson('/files/upload/initiate', ['original_name' => 'a.pdf', 'total_bytes' => 10])
            ->assertCreated();
    }
}
