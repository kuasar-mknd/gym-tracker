<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

/*
 * Supprimer ce qui avait atteint un objectif le rouvre (#1953).
 *
 * #1501 a rendu `checkCompletion()` capable de dé-marquer un objectif, mais
 * aucune suppression de séance, de ligne ou de série ne relançait le recalcul,
 * et un objectif de charge ou de volume que plus aucune série ne soutenait
 * gardait sa dernière valeur. Une série saisie à 1 000 kg au lieu de 100 puis
 * supprimée laissait l'objectif « 100 kg » atteint, à 1 000 kg, pour de bon.
 *
 * Chaque cas passe par la route, comme l'utilisateur : la file est synchrone
 * dans les tests, le recalcul se fait donc dans la requête.
 */

/**
 * Un objectif de charge ou de volume sur l'exercice, au départ donné.
 */
function suppressionObjectifSurExercice(User $user, Exercise $exercise, GoalType $type, float $depart, float $cible): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => $type,
        'start_value' => $depart,
        'current_value' => $depart,
        'target_value' => $cible,
        'completed_at' => null,
    ]);
}

/**
 * Une série validée, dans sa propre séance : son enregistrement tient les
 * records et relance le recalcul des objectifs.
 */
function suppressionSerieValidee(User $user, Exercise $exercise, float $poids, int $repetitions = 5): Set
{
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    return Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
        'is_completed' => true,
    ]);
}

/**
 * @return array{0: User, 1: Exercise}
 */
function suppressionPratiquant(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    return [$user, $exercise];
}

it('rouvre un objectif de fréquence quand on supprime la séance qui l’atteignait', function (): void {
    $user = User::factory()->create();
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 2,
        'completed_at' => null,
    ]);

    Workout::factory()->create(['user_id' => $user->id]);
    $seconde = Workout::factory()->create(['user_id' => $user->id]);

    expect($objectif->refresh()->completed_at)->not->toBeNull();

    actingAs($user)
        ->delete(route('workouts.destroy', $seconde))
        ->assertRedirect(route('workouts.index'));

    $objectif->refresh();

    expect($objectif->current_value)->toBe(1.0)
        ->and($objectif->progress_pct)->toBe(50.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('ramène un objectif de charge au record restant quand on supprime la série qui le portait', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Weight, 0, 100);

    suppressionSerieValidee($user, $exercise, 80);
    $faute = suppressionSerieValidee($user, $exercise, 500);

    expect($objectif->refresh()->current_value)->toBe(500.0)
        ->and($objectif->completed_at)->not->toBeNull();

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $faute))
        ->assertNoContent();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(80.0)
        ->and($objectif->progress_pct)->toBe(80.0)
        ->and($objectif->completed_at)->toBeNull();
});

/*
 * La valeur de départ, et non zéro ni la dernière valeur : c'est ce que
 * l'utilisateur a déclaré soulever en créant l'objectif, et ce qu'il redevient
 * quand plus aucune série ne dit mieux.
 */
it('ramène un objectif de charge à sa valeur de départ quand plus aucune série ne le soutient', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Weight, 60, 100);

    $faute = suppressionSerieValidee($user, $exercise, 1000);

    expect($objectif->refresh()->current_value)->toBe(1000.0)
        ->and($objectif->completed_at)->not->toBeNull();

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $faute))
        ->assertNoContent();

    $objectif->refresh();

    expect(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($objectif->current_value)->toBe(60.0)
        ->and($objectif->progress_pct)->toBe(0.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('ramène un objectif de volume à la meilleure séance restante quand on supprime une série', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Volume, 0, 1000);

    suppressionSerieValidee($user, $exercise, 50, 10);
    $faute = suppressionSerieValidee($user, $exercise, 400, 10);

    expect($objectif->refresh()->current_value)->toBe(4000.0)
        ->and($objectif->completed_at)->not->toBeNull();

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $faute))
        ->assertNoContent();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(500.0)
        ->and($objectif->progress_pct)->toBe(50.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('ramène un objectif de volume à sa valeur de départ quand on retire l’exercice de la séance', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Volume, 200, 1000);

    $faute = suppressionSerieValidee($user, $exercise, 400, 10);

    expect($objectif->refresh()->completed_at)->not->toBeNull();

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.workout-lines.destroy', $faute->workout_line_id))
        ->assertNoContent();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(200.0)
        ->and($objectif->progress_pct)->toBe(0.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('rouvre un objectif de charge quand on retire l’exercice qui le portait', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Weight, 0, 100);

    suppressionSerieValidee($user, $exercise, 90);
    $faute = suppressionSerieValidee($user, $exercise, 1000);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.workout-lines.destroy', $faute->workout_line_id))
        ->assertNoContent();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(90.0)
        ->and($objectif->completed_at)->toBeNull();
});

/*
 * La séance emporte ses lignes et ses séries par la cascade de la base, sans
 * aucun événement de série : seul `Workout::deleted` sait qu'elles sont
 * parties, et le recalcul doit lire les records APRÈS leur reconstruction.
 */
it('rouvre un objectif de charge quand on supprime la séance qui le portait', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Weight, 0, 100);

    suppressionSerieValidee($user, $exercise, 70);
    $faute = suppressionSerieValidee($user, $exercise, 500);
    $seance = Workout::query()->whereHas('workoutLines', fn ($lignes) => $lignes->whereKey($faute->workout_line_id))->firstOrFail();

    actingAs($user)
        ->delete(route('workouts.destroy', $seance))
        ->assertRedirect(route('workouts.index'));

    $objectif->refresh();

    expect($objectif->current_value)->toBe(70.0)
        ->and($objectif->completed_at)->toBeNull();
});

/*
 * Le recalcul suit la valeur sans rouvrir un objectif que le record restant
 * atteint encore : sa date d'atteinte ne bouge pas.
 */
it('laisse atteint, à sa date, un objectif que les séries restantes soutiennent encore', function (): void {
    [$user, $exercise] = suppressionPratiquant();
    $objectif = suppressionObjectifSurExercice($user, $exercise, GoalType::Weight, 0, 100);

    $this->freezeTime();
    suppressionSerieValidee($user, $exercise, 120);
    $atteintLe = $objectif->refresh()->completed_at;

    $this->travel(1)->days();
    $faute = suppressionSerieValidee($user, $exercise, 1200);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $faute))
        ->assertNoContent();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(120.0)
        ->and($objectif->completed_at?->toDateTimeString())->toBe($atteintLe?->toDateTimeString());
});
