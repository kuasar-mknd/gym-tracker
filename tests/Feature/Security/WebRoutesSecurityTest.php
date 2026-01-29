<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebRoutesSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that guest users are redirected to login when accessing protected resources.
     */
    public function test_guest_cannot_access_protected_routes()
    {
        $routes = [
            route('supplements.index'),
            route('habits.index'),
            route('goals.index'),
            route('templates.index'),
            route('exercises.index'),
            route('body-measurements.index'),
            route('body-parts.index'),
            route('plates.index'),
            route('daily-journals.index'),
            route('tools.index'),
        ];

        foreach ($routes as $route) {
            $response = $this->get($route);
            $response->assertRedirect(route('login'));
        }
    }

    public function test_authenticated_user_can_access_protected_routes()
    {
        // Mock manifest to avoid Inertia errors if build is missing
        $this->instance('vite.manifest', []);

        $user = \App\Models\User::factory()->create();

        $routes = [
            route('supplements.index'),
            route('habits.index'),
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($user)->get($route);
            // We expect 200 or successful render
            // Since we didn't build assets, Inertia might fail if it checks manifest,
            // but usually it just returns the component.
            $response->assertStatus(200);
        }
    }
}
