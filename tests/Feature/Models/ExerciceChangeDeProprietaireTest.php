<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;

/*
 * Réaffecter un exercice depuis le panneau doit oublier la liste de l'ancien
 * propriétaire comme celle du nouveau, et renouveler le catalogue commun
 * quand l'exercice le quitte ou le rejoint. Tant qu'un objectif, un gabarit
 * ou une séance le vise, son propriétaire ne change pas.
 */

it('oublie la liste de l’ancien propriétaire comme celle du nouveau', function (): void {
    $ancien = User::factory()->create();
    $nouveau = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $ancien->id]);

    expect(Exercise::enCachePourUtilisateur($ancien->id)->pluck('id'))->toContain($exercice->id);
    Exercise::enCachePourUtilisateur($nouveau->id);

    $exercice->user_id = $nouveau->id;
    $exercice->save();

    expect(Exercise::enCachePourUtilisateur($ancien->id)->pluck('id'))->not->toContain($exercice->id)
        ->and(Exercise::enCachePourUtilisateur($nouveau->id)->pluck('id'))->toContain($exercice->id);
});

it('renouvelle le catalogue quand l’exercice le quitte', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null]);

    expect(Exercise::enCachePourUtilisateur($compte->id)->pluck('id'))->toContain($exercice->id);

    $exercice->user_id = User::factory()->create()->id;
    $exercice->save();

    expect(Exercise::enCachePourUtilisateur($compte->id)->pluck('id'))->not->toContain($exercice->id);
});

it('renouvelle le catalogue quand l’exercice le rejoint', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(Exercise::enCachePourUtilisateur($compte->id)->pluck('id'))->not->toContain($exercice->id);

    $exercice->user_id = null;
    $exercice->save();

    expect(Exercise::enCachePourUtilisateur($compte->id)->pluck('id'))->toContain($exercice->id);
});

it('change de propriétaire tant que rien ne le vise', function (): void {
    $exercice = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);
    $nouveau = User::factory()->create();

    $exercice->user_id = $nouveau->id;
    $exercice->save();

    expect($exercice->refresh()->user_id)->toBe($nouveau->id);
});

it('refuse de changer de propriétaire quand un objectif, une ligne de gabarit ou de séance le vise', function (Closure $viser): void {
    $ancien = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $ancien->id]);
    $viser($exercice, $ancien);

    expect($exercice->estReference())->toBeTrue();

    $exercice->user_id = User::factory()->create()->id;

    expect(fn () => $exercice->save())->toThrow(LogicException::class);
    expect($exercice->refresh()->user_id)->toBe($ancien->id);
})->with([
    'un objectif' => [static fn (Exercise $e, User $u): Goal => Goal::factory()->create(['user_id' => $u->id, 'exercise_id' => $e->id, 'type' => 'weight'])],
    'une ligne de séance' => [static fn (Exercise $e, User $u): WorkoutLine => WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create(['user_id' => $u->id])->id, 'exercise_id' => $e->id])],
    'une ligne de gabarit' => [static fn (Exercise $e, User $u) => WorkoutTemplate::factory()->create(['user_id' => $u->id])->workoutTemplateLines()->create(['exercise_id' => $e->id, 'order' => 0])],
]);
