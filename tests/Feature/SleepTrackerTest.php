<?php

namespace Tests\Feature;

use App\Models\SleepLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SleepTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_sleep_index_renders_component()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('tools.sleep.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tools/SleepTracker')
                ->has('logs')
                ->has('history')
                ->has('lastLog')
            );
    }

    public function test_user_can_create_sleep_log()
    {
        $user = User::factory()->create();

        $startedAt = now()->subHours(8);
        $endedAt = now();

        $this->actingAs($user)
            ->post(route('tools.sleep.store'), [
                'started_at' => $startedAt->toDateTimeString(),
                'ended_at' => $endedAt->toDateTimeString(),
                'quality' => 4,
                'notes' => 'Good sleep',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sleep_logs', [
            'user_id' => $user->id,
            'quality' => 4,
            'notes' => 'Good sleep',
        ]);
    }

    public function test_user_cannot_delete_others_sleep_log()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $log = SleepLog::create([
            'user_id' => $user2->id,
            'started_at' => now()->subHours(8),
            'ended_at' => now(),
            'quality' => 3,
        ]);

        $this->actingAs($user1)
            ->delete(route('tools.sleep.destroy', $log))
            ->assertStatus(403);

        $this->assertDatabaseHas('sleep_logs', ['id' => $log->id]);
    }

    public function test_user_can_delete_own_sleep_log()
    {
        $user = User::factory()->create();
        $log = SleepLog::create([
            'user_id' => $user->id,
            'started_at' => now()->subHours(8),
            'ended_at' => now(),
            'quality' => 3,
        ]);

        $this->actingAs($user)
            ->delete(route('tools.sleep.destroy', $log))
            ->assertRedirect();

        $this->assertDatabaseMissing('sleep_logs', ['id' => $log->id]);
    }
}
