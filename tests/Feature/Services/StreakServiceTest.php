<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Support\Carbon;

covers(StreakService::class);

beforeEach(function (): void {
    $this->streakService = app(StreakService::class);
    // 🧪 Testing Pattern/Time: Use setTestNow for deterministic streak tests
    Carbon::setTestNow(Carbon::parse('2025-03-25 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
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

/**
 * Une seconde séance le même jour repousse l'heure, et l'écrit.
 *
 * Le test voisin compare `last_workout_at` au jour près (`startOfDay`) : il ne
 * peut donc pas voir si l'heure a bougé, ni même si quoi que ce soit a été
 * enregistré. Retirer purement et simplement le `$user->save()` de cette
 * branche le laissait vert — l'ancienne valeur, du même jour, satisfaisait
 * l'assertion.
 *
 * Ce que la branche fait vraiment : garder la séance la PLUS RÉCENTE de la
 * journée. C'est ce que lit la bannière « séance en cours » et ce qui décide du
 * rappel quotidien, donc l'heure compte, pas seulement la date.
 */
it('repousse l’heure de la dernière séance quand une seconde arrive le même jour', function (): void {
    $matin = Carbon::now()->startOfDay()->addHours(9);
    $soir = Carbon::now()->startOfDay()->addHours(19);

    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => $matin,
    ]);

    $seconde = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $soir,
    ]);

    $this->streakService->updateStreak($user, $seconde);

    // Relu depuis la base : c'est l'écriture qui est en cause, pas la valeur
    // portée par l'objet en mémoire.
    $enBase = User::findOrFail($user->id);

    expect(Carbon::parse($enBase->last_workout_at)->equalTo($soir))->toBeTrue()
        ->and($enBase->current_streak)->toBe(1);
});
