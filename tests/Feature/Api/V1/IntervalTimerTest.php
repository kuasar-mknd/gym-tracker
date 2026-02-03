<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\IntervalTimer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntervalTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_interval_timers(): void
    {
        $user = User::factory()->create();
        IntervalTimer::factory()->count(3)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.interval-timers.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_create_interval_timer(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'name' => 'Tabata',
            'work_seconds' => 20,
            'rest_seconds' => 10,
            'rounds' => 8,
            'warmup_seconds' => 30,
        ];

        $response = $this->postJson(route('api.v1.interval-timers.store'), $data);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Tabata')
            ->assertJsonPath('data.work_seconds', 20);

        $this->assertDatabaseHas('interval_timers', [
            'user_id' => $user->id,
            'name' => 'Tabata',
            'work_seconds' => 20,
        ]);
    }

    public function test_user_can_view_interval_timer(): void
    {
        $user = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.interval-timers.show', $timer));

        $response->assertOk()
            ->assertJsonPath('data.id', $timer->id)
            ->assertJsonPath('data.name', $timer->name);
    }

    public function test_user_cannot_view_others_interval_timer(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.interval-timers.show', $timer));

        $response->assertForbidden();
    }

    public function test_user_can_update_interval_timer(): void
    {
        $user = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $data = [
            'name' => 'Updated Timer',
            'work_seconds' => 45,
        ];

        $response = $this->putJson(route('api.v1.interval-timers.update', $timer), $data);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Timer')
            ->assertJsonPath('data.work_seconds', 45);

        $this->assertDatabaseHas('interval_timers', [
            'id' => $timer->id,
            'name' => 'Updated Timer',
            'work_seconds' => 45,
        ]);
    }

    public function test_user_cannot_update_others_interval_timer(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.interval-timers.update', $timer), [
            'name' => 'Hacked',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_interval_timer(): void
    {
        $user = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.interval-timers.destroy', $timer));

        $response->assertNoContent();
        $this->assertDatabaseMissing('interval_timers', ['id' => $timer->id]);
    }

    public function test_user_cannot_delete_others_interval_timer(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.interval-timers.destroy', $timer));

        $response->assertForbidden();
    }

    public function test_validation_errors(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.interval-timers.store'), [
            'name' => '', // Required
            'work_seconds' => 0, // Min 1
            'rest_seconds' => -1, // Min 0
            'rounds' => 0, // Min 1
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'work_seconds', 'rest_seconds', 'rounds']);
    }
}
