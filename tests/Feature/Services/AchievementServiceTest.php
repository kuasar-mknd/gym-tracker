<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Achievement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(AchievementService::class);
    Notification::fake();
});

test('it awards count achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'count',
        'threshold' => 1,
        'slug' => 'first-workout',
    ]);

    // Perform workout
    Workout::factory()->create(['user_id' => $user->id]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);

    Notification::assertSentTo(
        $user,
        \App\Notifications\AchievementUnlocked::class,
        fn ($notification): bool => $notification->achievement->id === $achievement->id
    );
});

test('it awards weight_record achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'weight_record',
        'threshold' => 100,
        'slug' => 'heavy-lifter',
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 1,
    ]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it awards volume_total achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'volume_total',
        'threshold' => 1000,
        'slug' => 'volume-novice',
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    // Set 1: 50 * 10 = 500
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    // Set 2: 50 * 10 = 500
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it awards streak achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 3,
        'slug' => 'streak-3',
    ]);

    // Create 3 workouts on 3 consecutive days
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(2)]);
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(1)]);
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it does not award achievement if threshold not met', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'weight_record',
        'threshold' => 100,
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 99,
        'reps' => 1,
    ]);

    $user->refresh();
    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it does not award achievement based on other users data', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $achievement = Achievement::factory()->create([
        'type' => 'volume_total',
        'threshold' => 1000,
    ]);

    // Other user meets criteria
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ]); // 1000 volume

    // User A has no workouts
    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);

    // User A has smaller workout
    $userWorkout = Workout::factory()->create(['user_id' => $user->id]);
    $userLine = WorkoutLine::factory()->create(['workout_id' => $userWorkout->id]);
    Set::factory()->create([
        'workout_line_id' => $userLine->id,
        'weight' => 10,
        'reps' => 10,
    ]); // 100 volume

    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it does not duplicate achievement assignments', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'count',
        'threshold' => 1,
    ]);

    Workout::factory()->create(['user_id' => $user->id]);

    // First sync
    $this->service->syncAchievements($user);
    $this->assertEquals(1, $user->achievements()->count());

    // Second sync
    $this->service->syncAchievements($user);
    $this->assertEquals(1, $user->achievements()->count());

    Notification::assertSentTimes(\App\Notifications\AchievementUnlocked::class, 1);
});
