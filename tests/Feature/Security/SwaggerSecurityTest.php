<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwaggerSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_swagger_documentation_ui_is_not_publicly_accessible(): void
    {
        $response = $this->get('/api/documentation');

        // Should redirect to login
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }

    public function test_swagger_docs_json_is_not_publicly_accessible(): void
    {
        // Based on route:list, the route is 'docs'
        $response = $this->get('/docs');

        // Should redirect to login
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_swagger_documentation_ui(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api/documentation');

        $response->assertStatus(200);
    }
}
