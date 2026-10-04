<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PulseCspTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable CSP and Pulse for testing
        Config::set('csp.enabled', true);
        Config::set('pulse.enabled', true);
        Config::set('app.debug', false);
    }

    public function test_pulse_dashboard_has_secure_csp_headers_and_nonces_in_content(): void
    {
        $roleName = config('filament-shield.super_admin.name', 'super_admin');
        Role::create(['name' => $roleName, 'guard_name' => 'admin']);
        $admin = Admin::factory()->create();
        $admin->assignRole($roleName);

        $response = $this->actingAs($admin, 'admin')->get('/backoffice/pulse');

        $response->assertStatus(200);
        $response->assertHeader('Content-Security-Policy');

        $csp = $response->headers->get('Content-Security-Policy');

        // Extract nonce from CSP header
        preg_match("/'nonce-([^']+)'/", (string) $csp, $matches);
        $this->assertNotEmpty($matches[1], 'Nonce not found in CSP header');
        $nonce = $matches[1];

        // Les balises <script> et <style> se signent du nonce : 'unsafe-inline'
        // n'est admis que pour les attributs style (style-src-attr), qui ne
        // peuvent pas porter de nonce.
        foreach (['script-src', 'style-src'] as $directive) {
            if (preg_match('/(?:^|;)\s*'.$directive.' ([^;]*)/', (string) $csp, $sources) !== 1) {
                $this->fail("Directive {$directive} absente de la Content-Security-Policy.");
            }

            $this->assertStringNotContainsString("'unsafe-inline'", $sources[1]);
        }

        // Verify that nonces are added to tags in the response content
        $content = (string) $response->getContent();
        $this->assertStringContainsString('<script nonce="'.$nonce.'">', $content);
        $this->assertStringContainsString('<style nonce="'.$nonce.'">', $content);
    }

    /**
     * Pulse a sa propre pile et lit `app('csp-nonce')` : deux requêtes servies
     * par la même application doivent quand même porter deux nonces, chacun
     * celui de son en-tête (#1904).
     */
    public function test_pulse_tire_un_nonce_neuf_a_chaque_requete(): void
    {
        $nomDuRole = config('filament-shield.super_admin.name');
        $nomDuRole = is_string($nomDuRole) ? $nomDuRole : 'super_admin';
        Role::create(['name' => $nomDuRole, 'guard_name' => 'admin']);
        $administrateur = Admin::factory()->create();
        $administrateur->assignRole($nomDuRole);

        $nonces = [];

        foreach ([1, 2] as $requete) {
            $reponse = $this->actingAs($administrateur, 'admin')->get('/backoffice/pulse');

            $reponse->assertStatus(200);
            if (preg_match("/'nonce-([^']+)'/", (string) $reponse->headers->get('Content-Security-Policy'), $trouve) !== 1) {
                $this->fail('Aucun nonce dans la Content-Security-Policy.');
            }

            $this->assertStringContainsString('<script nonce="'.$trouve[1].'">', (string) $reponse->getContent());
            $nonces[$requete] = $trouve[1];
        }

        $this->assertNotSame($nonces[1], $nonces[2]);
    }
}
