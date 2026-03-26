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

it('initializes streak to 1 on the first workout', function (): void {
    $user = User::factory()->create([
        'current_streak' => 0,
        'longest_streak' => 0,
        'last_workout_at' => null,
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(1),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('increments streak on consecutive workouts', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('resets streak if more than one day passes', function (): void {
    $user = User::factory()->create([
        'current_streak' => 5,
        'longest_streak' => 5,
        'last_workout_at' => Carbon::now()->subDays(3),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(5)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('does not increment streak on same day workouts', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 3, 26, 12, 0, 0));

    $user = User::factory()->create([
        'current_streak' => 3,
        'longest_streak' => 3,
        'last_workout_at' => Carbon::now(),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->addHours(2),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(3)
        ->and($user->longest_streak)->toBe(3)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('updates longest streak if current streak surpasses it', function (): void {
    $user = User::factory()->create([
        'current_streak' => 5,
        'longest_streak' => 5,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(6)
        ->and($user->longest_streak)->toBe(6);
});

it('updates streak correctly without passing workout parameter', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user);

    $user->refresh();

    expect($user->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});
