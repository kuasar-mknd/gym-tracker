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

    public function test_dashboard_has_consistent_csp_nonces(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertHeader('Content-Security-Policy');

        $csp = $response->headers->get('Content-Security-Policy');

        // Extract nonce from CSP header
        preg_match("/'nonce-([^']+)'/", (string) $csp, $matches);
        $this->assertNotEmpty($matches[1], 'Nonce not found in CSP header');
        $nonce = $matches[1];

        // Verify that the same nonce is used in the HTML for Vite and Sentry
        $content = (string) $response->getContent();

        // Check meta tag nonce (from app.blade.php line 9)
        $this->assertStringContainsString('<meta property="csp-nonce" content="'.$nonce.'">', $content);

        // Check Sentry script nonce (from app.blade.php line 30)
        $this->assertStringContainsString('<script nonce="'.$nonce.'">', $content);

        // Check Ziggy script nonce (from app.blade.php line 38 - @routes)
        $this->assertStringContainsString('nonce="'.$nonce.'"', $content);
    }
}
