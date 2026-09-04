<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

/**
 * @return array{0: WorkoutLine, 1: list<int>}
 */
function exerciceAvecSeries(User $proprietaire, int $combien = 3): array
{
    $seance = Workout::factory()->create(['user_id' => $proprietaire->id]);
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'exercise_id' => Exercise::factory()->create()->id,
    ]);

    $ids = [];

    foreach (range(0, $combien - 1) as $rang) {
        $ids[] = Set::factory()->create([
            'workout_line_id' => $ligne->id,
            'order' => $rang,
        ])->id;
    }

    return [$ligne, $ids];
}

it('renumerote les series depuis l ordre soumis', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$ligne, $ids] = exerciceAvecSeries($proprietaire);
    $voulu = [$ids[2], $ids[0], $ids[1]];

    $this->patchJson(route('api.v1.workout-lines.set-order', $ligne), ['sets' => $voulu])->assertOk();

    expect($ligne->sets()->pluck('id')->all())->toBe($voulu)
        ->and($ligne->sets()->pluck('order')->all())->toBe([0, 1, 2]);
});

/*
 * L'index `(workout_line_id, order)` peut rendre le bon ordre par accident de
 * plan : un temoin de comportement passerait alors avant comme apres. La
 * garantie qui manquait est la CLAUSE, pas le resultat.
 */
it('demande un ordre total a la base', function (): void {
    $proprietaire = User::factory()->create();
    [$ligne] = exerciceAvecSeries($proprietaire);

    expect($ligne->sets()->toSql())->toContain('order by `order` asc, `id` asc');
});

it('place une serie ajoutee en derniere position', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$ligne, $ids] = exerciceAvecSeries($proprietaire);

    // La colonne vaut zero par defaut : sans rang explicite, la nouvelle serie
    // se placerait EN TETE de l'exercice.
    $this->postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $ligne->id,
        'weight' => 60,
        'reps' => 8,
    ])->assertCreated();

    expect($ligne->sets()->pluck('id')->all())->toBe([...$ids, Set::max('id')]);
});

it('refuse une liste incomplete', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$ligne, $ids] = exerciceAvecSeries($proprietaire);

    $this->patchJson(route('api.v1.workout-lines.set-order', $ligne), ['sets' => [$ids[0], $ids[1]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sets');
});

it('refuse une serie qui appartient a un autre exercice', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$ligne, $ids] = exerciceAvecSeries($proprietaire);
    [, $autres] = exerciceAvecSeries($proprietaire);

    $this->patchJson(route('api.v1.workout-lines.set-order', $ligne), [
        'sets' => [$ids[0], $ids[1], $autres[0]],
    ])->assertUnprocessable();
});

it('refuse l exercice d autrui', function (): void {
    $proprietaire = User::factory()->create();
    [$ligne, $ids] = exerciceAvecSeries($proprietaire);

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson(route('api.v1.workout-lines.set-order', $ligne), ['sets' => $ids])
        ->assertNotFound();
});

it('ecrit en une seule requete, quel que soit le nombre de series', function (): void {
    $proprietaire = User::factory()->create();
    [$ligne, $ids] = exerciceAvecSeries($proprietaire, combien: 12);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(\App\Actions\Workouts\ReorderAction::class)->execute($ligne->sets(), array_values(array_reverse($ids)), 'sets');
    $ecritures = collect(\Illuminate\Support\Facades\DB::getQueryLog())
        ->filter(fn (array $r): bool => str_starts_with((string) $r['query'], 'update'));
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // Une par serie rendrait un ordre intermediaire lisible entre deux ecritures.
    expect($ecritures)->toHaveCount(1)
        ->and($ligne->sets()->pluck('id')->all())->toBe(array_reverse($ids));
});
