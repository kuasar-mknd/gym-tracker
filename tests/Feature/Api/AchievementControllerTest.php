<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_authenticated_user_can_list_achievements(): void
    {
        Achievement::factory()->count(3)->create();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson(route('api.v1.achievements.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'slug',
                        'name',
                        'description',
                        'icon',
                        'type',
                        'threshold',
                        'category',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_authenticated_user_can_view_single_achievement(): void
    {
        $achievement = Achievement::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson(route('api.v1.achievements.show', $achievement));

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $achievement->id,
                    'slug' => $achievement->slug,
                    'name' => $achievement->name,
                ],
            ]);
    }

    public function test_standard_user_cannot_create_achievement(): void
    {
        $user = User::factory()->create();

        $data = [
            'slug' => 'new-achievement',
            'name' => 'New Achievement',
            'description' => 'Test Description',
            'icon' => 'test-icon',
            'type' => 'test-type',
            'threshold' => 10,
            'category' => 'test-category',
        ];

        $response = $this->actingAs($user)
            ->postJson(route('api.v1.achievements.store'), $data);

        $response->assertForbidden();
    }

    public function test_standard_user_cannot_update_achievement(): void
    {
        $achievement = Achievement::factory()->create();
        $user = User::factory()->create();

        $data = [
            'name' => 'Updated Name',
        ];

        $response = $this->actingAs($user)
            ->putJson(route('api.v1.achievements.update', $achievement), $data);

        $response->assertForbidden();
    }

    public function test_standard_user_cannot_delete_achievement(): void
    {
        $achievement = Achievement::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson(route('api.v1.achievements.destroy', $achievement));

        $response->assertForbidden();
    }
}
