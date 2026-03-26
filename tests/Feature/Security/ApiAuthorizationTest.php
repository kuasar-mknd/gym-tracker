<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Goal;
use App\Models\IntervalTimer;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_update_another_users_personal_record_via_api(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $personalRecord = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.personal-records.update', $personalRecord), [
            'value' => 200,
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_update_another_users_interval_timer_via_api(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $intervalTimer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.interval-timers.update', $intervalTimer), [
            'name' => 'Stolen Timer',
            'work_seconds' => 60,
            'rest_seconds' => 30,
            'rounds' => 10,
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_update_another_users_goal_via_api(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.goals.update', $goal), [
            'title' => 'Stolen Goal',
        ]);

        $response->assertForbidden();
    }
}
