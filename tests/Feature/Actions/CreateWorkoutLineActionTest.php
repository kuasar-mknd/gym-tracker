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
    $ligne = $action->execute($workout, [
        'exercise_id' => $exercise->id,
    ]);

    expect($ligne->order)->toBe(0)
        ->and($ligne->workout_id)->toBe($workout->id)
        ->and($ligne->exercise_id)->toBe($exercise->id);
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
    $ligne = $action->execute($workout, [
        'exercise_id' => $exercise->id,
    ]);

    expect($ligne->order)->toBe(5);
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
    $ligne = $action->execute($workout, [
        'exercise_id' => $exercise->id,
        'order' => 10,
    ]);

    expect($ligne->order)->toBe(10);
});

/*
 * Le chemin idempotent etait execute mais jamais verifie : `InstanceOfToFalse`
 * sur le `if ($existing instanceof WorkoutLine)` et `RemoveEarlyReturn` sur le
 * `return $existing;` survivaient tous les deux.
 *
 * Pourquoi ils sont invisibles a l'oeil nu : sans le raccourci, l'action va
 * jusqu'au `save()`, l'index unique (idempotency_key, workout_id) le refuse, le
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

/*
 * Deux clés qui ne diffèrent que par la casse nomment deux tentatives (#2097).
 * Sous `utf8mb4_unicode_ci`, la recherche de la seconde trouvait la ligne de
 * la première, et la rendait : la colonne se compare en `ascii_bin`, comme
 * celle des séances (`.ai/rules/actions.md`).
 */
it('crée deux lignes pour deux clés qui ne diffèrent que par la casse, et rend à chacune la sienne', function (string $cle, string $autreCle): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();
    $action = app(CreateWorkoutLineAction::class);

    $premiere = $action->execute($workout, ['exercise_id' => $exercise->id, 'idempotency_key' => $cle]);
    $seconde = $action->execute($workout, ['exercise_id' => $exercise->id, 'idempotency_key' => $autreCle]);

    expect($seconde->id)->not->toBe($premiere->id)
        ->and($seconde->idempotency_key)->toBe($autreCle)
        ->and($action->execute($workout, ['exercise_id' => $exercise->id, 'idempotency_key' => $cle])->id)->toBe($premiere->id)
        ->and($action->execute($workout, ['exercise_id' => $exercise->id, 'idempotency_key' => $autreCle])->id)->toBe($seconde->id)
        ->and(WorkoutLine::where('workout_id', $workout->id)->count())->toBe(2);
})->with([
    'minuscules puis capitales' => ['tentative-a', 'TENTATIVE-A'],
    'un UUID puis le même en capitales' => ['5f1e7a2c-2097-4d3b-8c1a-00000000000a', '5F1E7A2C-2097-4D3B-8C1A-00000000000A'],
    'une lettre' => ['a', 'A'],
]);
