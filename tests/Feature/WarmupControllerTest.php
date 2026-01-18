<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarmupPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarmupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_displays_warmup_calculator()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.warmup'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Tools/WarmupCalculator')
                ->has('preferences')
            );
    }

    public function test_can_create_preference()
    {
        $user = User::factory()->create();

        $data = [
            'name' => 'My Warmup',
            'sets_config' => [
                ['type' => 'bar', 'reps' => 10, 'value' => null],
                ['type' => 'percentage', 'reps' => 5, 'value' => 0.5],
            ],
        ];

        $response = $this->actingAs($user)->post(route('warmup.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('warmup_preferences', [
            'user_id' => $user->id,
            'name' => 'My Warmup',
        ]);
    }

    public function test_can_update_preference()
    {
        $user = User::factory()->create();
        $pref = WarmupPreference::factory()->create(['user_id' => $user->id]);

        $data = [
            'name' => 'Updated Warmup',
            'sets_config' => [
                ['type' => 'bar', 'reps' => 15, 'value' => null],
            ],
        ];

        $response = $this->actingAs($user)->put(route('warmup.update', $pref), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('warmup_preferences', [
            'id' => $pref->id,
            'name' => 'Updated Warmup',
        ]);
    }

    public function test_can_delete_preference()
    {
        $user = User::factory()->create();
        $pref = WarmupPreference::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->delete(route('warmup.destroy', $pref));

        $response->assertRedirect();
        $this->assertDatabaseMissing('warmup_preferences', ['id' => $pref->id]);
    }

    public function test_cannot_manage_others_preference()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $pref = WarmupPreference::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)->delete(route('warmup.destroy', $pref));
        $response->assertForbidden();

        $response = $this->actingAs($user2)->put(route('warmup.update', $pref), [
            'name' => 'Hacked',
            'sets_config' => [],
        ]);
        $response->assertForbidden();
    }
}
