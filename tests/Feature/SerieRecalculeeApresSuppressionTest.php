<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Support\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * #1460 : supprimer une séance ne recalculait rien.
 *
 * `last_workout_at` continuait de pointer une séance disparue — et c'est la
 * seule mémoire de `StreakService`. L'écart calculé à la séance SUIVANTE
 * partait donc d'une date fantôme, et cassait une série pourtant continue.
 */

function seanceLe(User $user, string $date): Workout
{
    return Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse($date.' 10:00:00'),
    ]);
}

it('fait reculer la dernière séance quand on supprime la plus récente', function (): void {
    $user = User::factory()->create();

    seanceLe($user, '2026-06-10');
    $laPlusRecente = seanceLe($user, '2026-06-11');

    expect($user->refresh()->last_workout_at?->toDateString())->toBe('2026-06-11');

    $laPlusRecente->delete();

    expect($user->refresh()->last_workout_at?->toDateString())->toBe('2026-06-10');
});

it('raccourcit la série quand la suppression brise la suite', function (): void {
    $user = User::factory()->create();

    // Trois jours consécutifs : la série vaut 3.
    seanceLe($user, '2026-06-09');
    $duMilieu = seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');

    expect($user->refresh()->current_streak)->toBe(3);

    // Retirer le jour du milieu casse la suite : il ne reste que le 11.
    $duMilieu->delete();

    expect($user->refresh()->current_streak)->toBe(1)
        ->and($user->refresh()->longest_streak)->toBe(1);
});

it('remet tout à zéro quand il ne reste aucune séance', function (): void {
    $user = User::factory()->create();

    $seule = seanceLe($user, '2026-06-11');
    expect($user->refresh()->current_streak)->toBe(1);

    $seule->delete();

    $user->refresh();

    expect($user->last_workout_at)->toBeNull()
        ->and($user->current_streak)->toBe(0)
        ->and($user->longest_streak)->toBe(0);
});

it('ne compte qu’une fois deux séances le même jour', function (): void {
    $user = User::factory()->create();

    seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');

    app(StreakService::class)->recalculerDepuisLesFaits($user);

    expect($user->refresh()->current_streak)->toBe(2);
});
