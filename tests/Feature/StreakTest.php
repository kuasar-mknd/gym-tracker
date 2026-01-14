<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_streak_increments_on_consecutive_days(): void
    {
        $user = User::factory()->create();

        // Day 1
        $d1 = Carbon::parse('2023-01-01 12:00:00');
        $user->workouts()->create(['started_at' => $d1, 'completed_at' => $d1->copy()->addHour()]);

        $this->assertEquals(1, $user->fresh()->current_streak);
        $this->assertEquals(1, $user->fresh()->longest_streak);

        // Day 2
        $d2 = Carbon::parse('2023-01-02 12:00:00');
        $user->workouts()->create(['started_at' => $d2, 'completed_at' => $d2->copy()->addHour()]);

        $this->assertEquals(2, $user->fresh()->current_streak);
        $this->assertEquals(2, $user->fresh()->longest_streak);
    }

    public function test_streak_does_not_increment_twice_same_day(): void
    {
        $user = User::factory()->create();

        $d1 = Carbon::parse('2023-01-01 10:00:00');
        $user->workouts()->create(['started_at' => $d1, 'completed_at' => $d1->copy()->addHour()]);

        $this->assertEquals(1, $user->fresh()->current_streak);

        // Later same day
        $d1_later = Carbon::parse('2023-01-01 18:00:00');
        $user->workouts()->create(['started_at' => $d1_later, 'completed_at' => $d1_later->copy()->addHour()]);

        $this->assertEquals(1, $user->fresh()->current_streak);
    }

    public function test_streak_resets_after_missed_day(): void
    {
        $user = User::factory()->create();

        // Day 1
        $d1 = Carbon::parse('2023-01-01 12:00:00');
        $user->workouts()->create(['started_at' => $d1]);
        $this->assertEquals(1, $user->fresh()->current_streak);

        // Skip Day 2, workout on Day 3
        $d3 = Carbon::parse('2023-01-03 12:00:00');
        $user->workouts()->create(['started_at' => $d3]);

        // Should reset to 1
        $this->assertEquals(1, $user->fresh()->current_streak);
        // Longest streak remains 1
        $this->assertEquals(1, $user->fresh()->longest_streak);
    }

    public function test_longest_streak_persists(): void
    {
        $user = User::factory()->create();

        // 3 days streak
        $user->workouts()->create(['started_at' => Carbon::parse('2023-01-01')]);
        $user->workouts()->create(['started_at' => Carbon::parse('2023-01-02')]);
        $user->workouts()->create(['started_at' => Carbon::parse('2023-01-03')]);

        $this->assertEquals(3, $user->fresh()->current_streak);
        $this->assertEquals(3, $user->fresh()->longest_streak);

        // Break streak (Day 5)
        $user->workouts()->create(['started_at' => Carbon::parse('2023-01-05')]);

        $this->assertEquals(1, $user->fresh()->current_streak);
        $this->assertEquals(3, $user->fresh()->longest_streak);
    }
}
