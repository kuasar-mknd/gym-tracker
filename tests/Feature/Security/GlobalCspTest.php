<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GlobalCspTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('csp.enabled', true);
    }

    public function test_dashboard_has_consistent_csp_headers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertHeader('Content-Security-Policy');

        $csp = $response->headers->get('Content-Security-Policy');

        // Nonces are disabled in 'testing' environment for Dusk stability.
        // So we only check for basic directives.
        $this->assertStringContainsString("script-src 'self'", (string) $csp);
        $this->assertStringContainsString("style-src 'self'", (string) $csp);
    }
}
