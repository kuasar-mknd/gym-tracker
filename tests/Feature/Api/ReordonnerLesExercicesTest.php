<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

/**
 * @return array{0: Workout, 1: list<int>}
 */
function seanceAReordonner(User $proprietaire, int $rangInitial = 0): array
{
    $seance = Workout::factory()->create(['user_id' => $proprietaire->id]);

    $ids = [];

    foreach (range(1, 3) as $ignore) {
        $ids[] = WorkoutLine::factory()->create([
            'workout_id' => $seance->id,
            'exercise_id' => Exercise::factory()->create()->id,
            'order' => $rangInitial,
        ])->id;
    }

    return [$seance, $ids];
}

it('renumerote les exercices depuis l ordre soumis', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$seance, $ids] = seanceAReordonner($proprietaire);
    $voulu = [$ids[2], $ids[0], $ids[1]];

    $this->patchJson(route('api.v1.workouts.line-order', $seance), ['lines' => $voulu])
        ->assertOk();

    expect($seance->workoutLines()->pluck('id')->all())->toBe($voulu);
});

/*
 * Deux lignes d'une meme seance peuvent partager un rang : l'index n'est pas
 * unique, le client peut fournir `order`, et deux creations concurrentes lisent
 * le meme maximum. Echanger deux rangs egaux n'ecrirait rien — la
 * renumerotation complete est donc une exigence, pas un choix.
 */
it('normalise des rangs tous a zero', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$seance, $ids] = seanceAReordonner($proprietaire, rangInitial: 0);
    $voulu = [$ids[1], $ids[2], $ids[0]];

    $this->patchJson(route('api.v1.workouts.line-order', $seance), ['lines' => $voulu])->assertOk();

    expect($seance->workoutLines()->pluck('order')->all())->toBe([0, 1, 2])
        ->and($seance->workoutLines()->pluck('id')->all())->toBe($voulu);
});

it('refuse une liste incomplete', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$seance, $ids] = seanceAReordonner($proprietaire);

    // Renumeroter deux lignes sur trois laisserait la troisieme sur son rang
    // d'origine, donc en double avec l'une des deux.
    $this->patchJson(route('api.v1.workouts.line-order', $seance), ['lines' => [$ids[0], $ids[1]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');
});

it('refuse une ligne qui appartient a une autre seance', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    [$seance, $ids] = seanceAReordonner($proprietaire);
    [$autre, $idsAutre] = seanceAReordonner($proprietaire);

    $this->patchJson(route('api.v1.workouts.line-order', $seance), [
        'lines' => [$ids[0], $ids[1], $idsAutre[0]],
    ])->assertUnprocessable();
});

it('refuse la seance d autrui', function (): void {
    $proprietaire = User::factory()->create();
    [$seance, $ids] = seanceAReordonner($proprietaire);

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson(route('api.v1.workouts.line-order', $seance), ['lines' => $ids])
        ->assertNotFound();
});

it('ecrit en une seule requete, quel que soit le nombre d exercices', function (): void {
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    $seance = Workout::factory()->create(['user_id' => $proprietaire->id]);

    $ids = collect(range(1, 12))->map(fn (): int => WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'exercise_id' => Exercise::factory()->create()->id,
    ])->id)->all();

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(\App\Actions\Workouts\ReorderAction::class)->execute($seance->workoutLines(), array_values(array_reverse($ids)), 'lines');
    $ecritures = collect(\Illuminate\Support\Facades\DB::getQueryLog())
        ->filter(fn (array $r): bool => str_starts_with((string) $r['query'], 'update'));
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // Une par ligne rendrait un ordre intermediaire lisible entre deux ecritures.
    expect($ecritures)->toHaveCount(1);
    expect($seance->workoutLines()->pluck('id')->all())->toBe(array_reverse($ids));
});
