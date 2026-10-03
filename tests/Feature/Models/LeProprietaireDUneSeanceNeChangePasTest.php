<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/*
 * Le propriétaire d'une séance est fixé à sa création (#1933).
 *
 * `workout_lines.user_id` et `sets.user_id` le recopient, et leurs écrivains ne
 * le relisent que quand la ligne change de séance ou la série de ligne. Une
 * séance qui changeait de compte laissait donc ses lignes et ses séries à
 * l'ancien, et toutes les lectures filtrées par propriétaire (statistiques,
 * records, autorisations) voyaient une séance de B dont les séries étaient à A.
 *
 * Le panneau ne l'offre plus ; le modèle le refuse, pour tout chemin qui passe
 * par lui, que la clef soit affectée, forcée ou posée par la relation.
 */
it('refuse de changer le propriétaire d’une séance enregistrée', function (Closure $tentative): void {
    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $avant->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id]);

    expect(fn (): mixed => $tentative($seance, $apres))
        ->toThrow(LogicException::class, 'ne change pas après sa création');

    expect(Workout::query()->findOrFail($seance->id)->user_id)->toBe($avant->id)
        ->and($ligne->refresh()->user_id)->toBe($avant->id)
        ->and($serie->refresh()->user_id)->toBe($avant->id);
})->with([
    'par affectation, comme la page de modification du panneau' => function (Workout $seance, User $compte): bool {
        $seance->user_id = $compte->id;

        return $seance->save();
    },
    'par remplissage forcé' => fn (Workout $seance, User $compte): bool => $seance->forceFill(['user_id' => $compte->id])->save(),
    'par la relation, comme le sélecteur du panneau' => fn (Workout $seance, User $compte): bool => $seance->user()->associate($compte)->save(),
]);

/*
 * Le refus ne vise que le propriétaire : une séance se renomme, se redate et se
 * termine comme avant, et la date recopiée sur ses lignes suit toujours.
 */
it('laisse modifier le reste d’une séance enregistrée', function (): void {
    $proprietaire = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $proprietaire->id, 'name' => 'Avant']);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);

    $seance->update([
        'name' => 'Après',
        'started_at' => '2026-09-01 07:00:00',
        'ended_at' => '2026-09-01 08:00:00',
    ]);

    $seance->refresh();

    expect($seance->user_id)->toBe($proprietaire->id)
        ->and($seance->name)->toBe('Après')
        ->and($ligne->refresh()->workout_started_at?->toDateTimeString())->toBe('2026-09-01 07:00:00');
});
