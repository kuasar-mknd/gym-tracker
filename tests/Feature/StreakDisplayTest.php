<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StreakDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_zero_streak_when_day_missed(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'current_streak' => 5,
            'last_workout_at' => Carbon::now()->subDays(2), // 2 days ago
        ])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(
                fn (AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
                    ->component('Dashboard')
                    ->where('auth.user.current_streak', 0) // Should be 0, currently likely 5
            );
    }

    public function test_dashboard_shows_correct_streak_when_streak_active(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'current_streak' => 5,
            'last_workout_at' => Carbon::now()->subDays(1), // Yesterday
        ])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(
                fn (AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
                    ->component('Dashboard')
                    ->where('auth.user.current_streak', 5)
            );
    }
}
