<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateWorkoutLineAction;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('sets order to 0 when no workout lines exist and order is not provided', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();

    $action = app(CreateWorkoutLineAction::class);
    $workoutLine = $action->execute($workout, [
        'exercise_id' => $exercise->id,
    ]);

    expect($workoutLine->order)->toBe(0)
        ->and($workoutLine->workout_id)->toBe($workout->id)
        ->and($workoutLine->exercise_id)->toBe($exercise->id);
});

it('auto-increments order based on max order when order is not provided', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 2,
    ]);

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 4,
    ]);

    $action = app(CreateWorkoutLineAction::class);
    $workoutLine = $action->execute($workout, [
        'exercise_id' => $exercise->id,
    ]);

    expect($workoutLine->order)->toBe(5);
});

it('respects explicitly provided order', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 2,
    ]);

    $action = app(CreateWorkoutLineAction::class);
    $workoutLine = $action->execute($workout, [
        'exercise_id' => $exercise->id,
        'order' => 10,
    ]);

    expect($workoutLine->order)->toBe(10);
});

/*
 * Le chemin idempotent etait execute mais jamais verifie : `InstanceOfToFalse`
 * sur le `if ($existing instanceof WorkoutLine)` et `RemoveEarlyReturn` sur le
 * `return $existing;` survivaient tous les deux.
 *
 * Pourquoi ils sont invisibles a l'oeil nu : sans le raccourci, l'action va
 * jusqu'au `save()`, l'index unique (workout_id, idempotency_key) le refuse, le
 * `catch` rattrape et rend la meme ligne. Le rejeu rend donc le BON objet dans
 * les deux cas — mais en passant par une ecriture refusee, c'est-a-dire en
 * s'appuyant sur une exception pour ce qui devrait etre une simple lecture.
 *
 * La difference se voit sur les requetes, et seulement la.
 */
it('rejoue une creation idempotente par une seule lecture, sans tenter d ecriture', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();
    $action = app(CreateWorkoutLineAction::class);

    $donnees = [
        'exercise_id' => $exercise->id,
        'idempotency_key' => 'rejeu-de-ligne-1',
    ];

    $premiere = $action->execute($workout, $donnees);

    $requetes = [];
    DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    $seconde = $action->execute($workout, $donnees);

    $pendantLeRejeu = $requetes;

    // Le rejeu rend la ligne deja creee, et n'en cree pas une seconde.
    expect($seconde->id)->toBe($premiere->id);
    expect($seconde->order)->toBe($premiere->order);

    /*
     * Une seule requete, et c'est une lecture.
     *
     * Sans le raccourci, ce rejeu en declenche quatre : la recherche, le
     * `max(order)`, l'insertion refusee — que Laravel ne journalise meme pas,
     * puisqu'elle leve — puis la relecture du gagnant. Le compte exact est donc
     * la seule assertion qui distingue les deux, et il dit ce qu'on veut dire :
     * un rejeu ne doit rien ecrire.
     */
    expect($pendantLeRejeu)->toHaveCount(1);
    expect(mb_strtolower($pendantLeRejeu[0]))->toStartWith('select');

    expect(WorkoutLine::where('workout_id', $workout->id)->count())->toBe(1);
});
