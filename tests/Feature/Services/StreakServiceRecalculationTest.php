<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Support\Carbon;

covers(StreakService::class);

beforeEach(function (): void {
    $this->streakService = app(StreakService::class);
});

it('recalculates historical streaks correctly for backdated workouts', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-03-25 12:00:00'));

    $user = User::factory()->create([
        'current_streak' => 0,
        'longest_streak' => 0,
        'last_workout_at' => null,
    ]);

    // Create a workout 3 days ago
    $workout1 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(3),
    ]);

    $this->streakService->updateStreak($user, $workout1);
    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1);

    // Create a workout 1 day ago
    $workout2 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(1),
    ]);

    $this->streakService->updateStreak($user, $workout2);
    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1);

    // Create a backdated workout 2 days ago (bridges the gap!)
    $workout3 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(2),
    ]);

    $this->streakService->updateStreak($user, $workout3);
    $user->refresh();

    // The gap is bridged, streak should be 3 from day -3, -2, -1
    expect($user->current_streak)->toBe(3)
        ->and($user->longest_streak)->toBe(3)
        ->and(Carbon::parse($user->last_workout_at)->equalTo($workout2->started_at))->toBeTrue();

    Carbon::setTestNow();
});

it('recalculates correctly if a middle workout is created out of order', function (): void {
    $user = User::factory()->create([
        'current_streak' => 0,
        'longest_streak' => 0,
        'last_workout_at' => null,
    ]);

    $workout1 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => '2025-03-20 10:00:00',
    ]);

    $workout3 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => '2025-03-22 10:00:00',
    ]);

    $this->streakService->updateStreak($user, $workout1);
    $this->streakService->updateStreak($user, $workout3);

    $user->refresh();
    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1);

    // Bridge the gap
    $workout2 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => '2025-03-21 10:00:00',
    ]);

    $this->streakService->updateStreak($user, $workout2);

    $user->refresh();
    expect($user->current_streak)->toBe(3)
        ->and($user->longest_streak)->toBe(3)
        ->and(Carbon::parse($user->last_workout_at)->equalTo($workout3->started_at))->toBeTrue();
});
