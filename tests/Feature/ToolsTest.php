<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_index_is_displayed_for_authenticated_user()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Tools/Index'));
    }

    public function test_1rm_calculator_is_displayed_for_authenticated_user()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.1rm'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Tools/OneRepMax'));
    }

    public function test_unauthenticated_user_cannot_access_tools()
    {
        $response = $this->get(route('tools.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_access_1rm_calculator()
    {
        $response = $this->get(route('tools.1rm'));

        $response->assertRedirect(route('login'));
    }
}
