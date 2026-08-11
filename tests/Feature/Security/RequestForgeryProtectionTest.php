<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Laravel 13 renamed VerifyCsrfToken to PreventRequestForgery, keeping the old
 * names as deprecated subclasses. Three separate places named the old class —
 * bootstrap/app.php, config/sanctum.php and the Filament panel — and not one of
 * the 1444 existing tests touched CSRF wiring, so pointing any of them at a class
 * that no longer exists would have surfaced only in production.
 *
 * These assert the wiring rather than a 419 response on purpose: the middleware
 * short-circuits on runningUnitTests(), so no HTTP-level test can observe it
 * rejecting a request.
 */
final class RequestForgeryProtectionTest extends TestCase
{
    public function test_web_routes_are_protected_against_request_forgery(): void
    {
        $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];

        $protection = array_filter(
            $webGroup,
            fn (mixed $middleware): bool => is_string($middleware)
                && is_a($middleware, PreventRequestForgery::class, true)
        );

        $this->assertNotEmpty(
            $protection,
            'The web middleware group must apply request forgery protection.'
        );
    }

    public function test_only_api_and_dusk_routes_are_exempt(): void
    {
        $except = new ReflectionClass(PreventRequestForgery::class)
            ->getProperty('neverVerify')
            ->getValue();

        $this->assertEqualsCanonicalizing(
            ['api/*', '_dusk/*'],
            $except,
            'Widening this list removes forgery protection from real routes.'
        );
    }

    /**
     * Sanctum appends whatever class this config names onto the stateful
     * pipeline without checking it, so a stale reference degrades silently.
     */
    public function test_sanctum_points_at_a_live_forgery_middleware(): void
    {
        $configured = config('sanctum.middleware.validate_csrf_token');

        $this->assertIsString($configured);
        $this->assertTrue(class_exists($configured), "{$configured} does not exist.");
        $this->assertTrue(
            is_a($configured, PreventRequestForgery::class, true),
            "{$configured} is not request forgery protection."
        );
    }

    public function test_the_admin_panel_keeps_forgery_protection(): void
    {
        $middleware = Filament::getPanel('admin')->getMiddleware();

        $protection = array_filter(
            $middleware,
            fn (mixed $entry): bool => is_string($entry)
                && is_a($entry, PreventRequestForgery::class, true)
        );

        $this->assertNotEmpty(
            $protection,
            'The admin panel must keep request forgery protection.'
        );
    }
}
