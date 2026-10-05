<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
 * #1986 a posé des plafonds sur les valeurs d'une série. Les séries et les
 * modèles enregistrés avant peuvent les dépasser, et ce qui les recopie sans
 * passer par la requête d'une série (la recommandation, le démarrage d'une
 * séance depuis un modèle) les ramène sous ces plafonds : recopiée telle
 * quelle, la valeur était refusée à chaque ajout de série, et l'exercice ne
 * pouvait plus en recevoir.
 */

/**
 * Un exercice du compte, fait il y a trois jours avec des valeurs au-delà
 * des plafonds d'une série, et la séance en cours du jour.
 *
 * @return array{0: Exercise, 1: Workout}
 */
function seriesAnterieuresSeanceApresDesValeursHorsBornes(User $compte): array
{
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength']);

    $ancienne = Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => now()->subDays(3),
        'ended_at' => now()->subDays(3)->addHour(),
    ]);
    $ligneAncienne = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercice->id]);

    Set::query()->insert(array_fill(0, 2, [
        'workout_line_id' => $ligneAncienne->id,
        'user_id' => $compte->id,
        'weight' => 150_000,
        'reps' => 1_500,
        'distance_km' => 2_000,
        'duration_seconds' => 90_000,
        'is_warmup' => false,
        'is_completed' => true,
    ]));

    $enCours = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now(), 'ended_at' => null]);

    return [$exercice, $enCours];
}

it('ramène la recommandation d’une ligne ajoutée aux plafonds d’une série, et la série qui la reprend est créée', function (): void {
    $compte = User::factory()->create();
    [$exercice, $enCours] = seriesAnterieuresSeanceApresDesValeursHorsBornes($compte);

    $ligne = actingAs($compte)
        ->postJson(route('api.v1.workout-lines.store'), ['workout_id' => $enCours->id, 'exercise_id' => $exercice->id])
        ->assertCreated()
        ->json('data');

    // Le JSON ne garde pas la virgule d'un flottant rond : 100000.0 revient 100000.
    expect($ligne['recommended_values'])->toEqual(Set::bornes());

    actingAs($compte)
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $ligne['id'],
            'is_completed' => false,
            'weight' => $ligne['recommended_values']['weight'],
            'reps' => $ligne['recommended_values']['reps'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.reps', Set::REPETITIONS_MAX);
});

it('ramène aux plafonds la recommandation que la page de séance reçoit, avec ces plafonds', function (): void {
    $compte = User::factory()->create();
    [$exercice, $enCours] = seriesAnterieuresSeanceApresDesValeursHorsBornes($compte);
    WorkoutLine::factory()->create(['workout_id' => $enCours->id, 'exercise_id' => $exercice->id]);

    actingAs($compte)
        ->get(route('workouts.show', $enCours))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Show')
            ->where('workout.workout_lines.0.recommended_values.reps', Set::REPETITIONS_MAX)
            ->where('workout.workout_lines.0.recommended_values.weight', Set::POIDS_MAX_KG)
            ->where('workout.workout_lines.0.recommended_values.distance_km', Set::DISTANCE_MAX_KM)
            ->where('workout.workout_lines.0.recommended_values.duration_seconds', Set::DUREE_MAX_SECONDES)
            ->where('bornesDUneSerie', Set::bornes())
        );
});

it('ramène aux plafonds d’une série les valeurs d’un modèle ancien quand une séance en démarre', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id]);
    $ligne = $modele->workoutTemplateLines()->create(['exercise_id' => $exercice->id, 'order' => 0]);
    $ligne->workoutTemplateSets()->create(['reps' => 1_500, 'weight' => 150_000, 'is_warmup' => false, 'order' => 0]);
    $ligne->workoutTemplateSets()->create(['reps' => 8, 'weight' => 62.5, 'is_warmup' => false, 'order' => 1]);
    $ligne->workoutTemplateSets()->create(['reps' => null, 'weight' => null, 'is_warmup' => true, 'order' => 2]);

    actingAs($compte)->post(route('templates.execute', $modele))->assertRedirect();

    $series = Set::query()->where('user_id', $compte->id)->orderBy('order')->get();

    expect($series->map(fn (Set $serie): array => [$serie->reps, $serie->weight])->all())->toBe([
        [Set::REPETITIONS_MAX, (float) Set::POIDS_MAX_KG],
        [8, 62.5],
        [null, null],
    ]);
});

it('ramène une valeur entre zéro et le plafond de son champ, et laisse une valeur absente ou dans les bornes telle quelle', function (): void {
    expect(Set::ramenerALaBorne('reps', 1_500))->toBe(Set::REPETITIONS_MAX)
        ->and(Set::ramenerALaBorne('weight', 150_000.5))->toBe(Set::POIDS_MAX_KG)
        ->and(Set::ramenerALaBorne('distance_km', 2_000.0))->toBe(Set::DISTANCE_MAX_KM)
        ->and(Set::ramenerALaBorne('duration_seconds', 90_000))->toBe(Set::DUREE_MAX_SECONDES)
        ->and(Set::ramenerALaBorne('reps', -3))->toBe(0)
        ->and(Set::ramenerALaBorne('weight', 62.5))->toBe(62.5)
        ->and(Set::ramenerALaBorne('reps', Set::REPETITIONS_MAX))->toBe(Set::REPETITIONS_MAX)
        ->and(Set::ramenerALaBorne('weight', null))->toBeNull();
});
