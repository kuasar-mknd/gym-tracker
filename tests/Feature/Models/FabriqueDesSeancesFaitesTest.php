<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/*
 * L'état `faite()` de la fabrique termine la séance une heure après son
 * début, et garde une fin donnée explicitement (#2110).
 *
 * Le début peut venir de trois endroits : un état posé avant `faite()`, les
 * attributs donnés à `create()` ou `make()`, ou un état posé après lui. La
 * fabrique applique les deux derniers après ses propres états : une fin
 * calculée dans un état ne voyait donc que le début par défaut, maintenant,
 * et une séance démarrée demain finissait aujourd'hui, avant d'avoir
 * commencé. `AchievementTest` en écrivait une ainsi.
 *
 * Les séances s'écrivent sans écouteurs : le test ne lit que leurs dates
 * (`.ai/rules/tests.md`).
 */

/**
 * Une séance faite de la fabrique, ses attributs donnés sous l'une des formes
 * qui passent après les états : à `create()`, à `make()`, ou par un état posé
 * après `faite()`.
 *
 * @param  array<string, mixed>  $attributs
 */
function fabriqueDesSeancesFaitesSous(string $forme, array $attributs): Workout
{
    return Workout::withoutEvents(fn (): Workout => match ($forme) {
        'create' => Workout::factory()->faite()->create($attributs)->refresh(),
        'make' => Workout::factory()->faite()->make($attributs),
        'state' => Workout::factory()->faite()->state($attributs)->make(),
        default => throw new InvalidArgumentException("Forme inconnue : {$forme}"),
    });
}

it('termine la séance faite une heure après le début qu’on lui donne, et garde la fin donnée', function (string $forme): void {
    Carbon::setTestNow('2026-10-30 12:00:00');
    $compte = User::factory()->create();
    $debut = Carbon::parse('2026-10-31 09:00:00');

    $sansFin = fabriqueDesSeancesFaitesSous($forme, ['user_id' => $compte->id, 'started_at' => $debut]);
    $avecFin = fabriqueDesSeancesFaitesSous($forme, [
        'user_id' => $compte->id,
        'started_at' => $debut,
        'ended_at' => Carbon::parse('2026-10-31 10:30:00'),
    ]);

    expect($sansFin->started_at->toDateTimeString())->toBe('2026-10-31 09:00:00')
        ->and($sansFin->ended_at?->toDateTimeString())->toBe('2026-10-31 10:00:00')
        ->and($avecFin->started_at->toDateTimeString())->toBe('2026-10-31 09:00:00')
        ->and($avecFin->ended_at?->toDateTimeString())->toBe('2026-10-31 10:30:00');
})->with([
    'dates données à create()' => 'create',
    'dates données à make()' => 'make',
    'dates posées par un état après faite()' => 'state',
]);
