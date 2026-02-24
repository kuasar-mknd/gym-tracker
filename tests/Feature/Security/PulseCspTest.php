<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Config;

class PulseCspTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable CSP and Pulse for testing
        Config::set('csp.enabled', true);
        Config::set('pulse.enabled', true);
    }

    public function test_pulse_dashboard_has_secure_csp_headers_and_nonces_in_content()
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
        preg_match("/'nonce-([^']+)'/", $csp, $matches);
        $this->assertNotEmpty($matches[1], "Nonce not found in CSP header");
        $nonce = $matches[1];

        // Verify that unsafe-inline is removed
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);

        // Verify that nonces are added to tags in the response content
        $content = $response->getContent();
        $this->assertStringContainsString("<script nonce=\"$nonce\">", $content);
        $this->assertStringContainsString("<style nonce=\"$nonce\">", $content);
    }
}
