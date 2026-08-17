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
        Config::set('csp.nonce_enabled', true);
        Config::set('app.debug', false);

        /*
         * Le bloc Sentry n'est rendu que si un DSN est configure : sans lui, une
         * installation tierce n'expose aucun objet global vide et n'envoie rien
         * (#1444). Ce test verifie que CE script porte bien le nonce, il doit
         * donc lui donner de quoi exister.
         */
        Config::set('sentry.dsn_public', 'https://exemple@o0.ingest.sentry.io/1');
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

        // Le script Sentry, rendu ici parce que setUp() a configure un DSN.
        $this->assertStringContainsString('<script nonce="'.$nonce.'">', $content);

        // Check Ziggy script nonce (from app.blade.php line 38 - @routes)
        $this->assertStringContainsString('nonce="'.$nonce.'"', $content);
    }
}
