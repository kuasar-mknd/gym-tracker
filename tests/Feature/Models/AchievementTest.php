<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\AchievementUnlocked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AchievementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed achievements
        $this->seed(\Database\Seeders\AchievementSeeder::class);
    }

    public function test_awards_first_workout_badge(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $premiereSeance = Achievement::where('slug', 'first-workout')->firstOrFail();

        // Une séance terminée sans série ne débloque plus rien (#2110) : elle le
        // débloquait, et ce test l'affirmait.
        $user->workouts()->create(['started_at' => now(), 'ended_at' => now()]);

        $this->assertDatabaseMissing('user_achievements', [
            'user_id' => $user->id,
            'achievement_id' => $premiereSeance->id,
        ]);
        Notification::assertNothingSentTo($user);

        // Une première séance faite : terminée, avec une série cochée.
        Workout::factory()->faite()->create(['user_id' => $user->id]);

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $user->id,
            'achievement_id' => Achievement::where('slug', 'first-workout')->firstOrFail()->id,
        ]);

        Notification::assertSentTo(
            $user,
            AchievementUnlocked::class,
            fn (AchievementUnlocked $notification, $channels): bool => $notification->achievement->slug === 'first-workout'
        );
    }

    public function test_awards_heavy_lifter_100_badge(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        $workout = $user->workouts()->create(['started_at' => now(), 'ended_at' => now()]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id]);

        // Lift 100kg
        $line->sets()->create([
            'weight' => 100,
            'reps' => 1,
            'is_completed' => true,
        ]);

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $user->id,
            'achievement_id' => Achievement::where('slug', 'heavy-lifter-100')->firstOrFail()->id,
        ]);
    }

    public function test_does_not_award_badges_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Unlock first workout
        Workout::factory()->faite()->create(['user_id' => $user->id]);

        // Check count
        $this->assertEquals(1, $user->achievements()->count());

        // Do another workout
        Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => now()->addDay()]);

        // Still 1 (assuming Next badge is at 3)
        $this->assertEquals(1, $user->achievements()->count());
    }

    public function test_awards_streak_badge(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // Create workouts on 3 consecutive days
        $user->workouts()->create(['started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)]);
        $user->workouts()->create(['started_at' => now()->subDays(1), 'ended_at' => now()->subDays(1)]);
        $user->workouts()->create(['started_at' => now(), 'ended_at' => now()]);

        $this->assertDatabaseHas('user_achievements', [
            'user_id' => $user->id,
            'achievement_id' => Achievement::where('slug', 'streak-3')->firstOrFail()->id,
        ]);
    }
}
