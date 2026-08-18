<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Un objectif se met a jour par deux chemins, et ils doivent dire la meme chose.
 *
 * `syncGoals()` traite tous les objectifs d'un coup, avec des metriques
 * pre-calculees en trois requetes, et tourne a chaque enregistrement de seance
 * via SyncUserGoals. `updateGoalProgress()` en traite un seul, avec une requete
 * par objectif, et tourne quand on modifie l'objectif depuis son ecran
 * (GoalController).
 *
 * Le pre-calcul n'est qu'une optimisation : a donnees egales, les deux doivent
 * rendre la meme valeur. Rien ne le verifiait, et ils ne le faisaient pas.
 */

/**
 * Un utilisateur, un exercice et un objectif de poids a 50 sur 100.
 *
 * @return array{0: User, 1: Exercise}
 */
function utilisateurAvecObjectifDePoids(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    return [$user, $exercise];
}

function objectifDePoids(User $user, Exercise $exercise, float $courant = 50): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => GoalType::Weight,
        'start_value' => 0,
        'target_value' => 100,
        'current_value' => $courant,
    ]);
}

/**
 * Une seance dont la serie porte le poids demande — null pour « aucun poids ».
 */
function seanceAvecPoids(User $user, Exercise $exercise, ?float $poids, int $repetitions = 10): void
{
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
    ]);
}

/**
 * Les deux chemins, sur des donnees identiques, doivent rendre la meme valeur.
 *
 * Le cas qui les separait : une serie sans poids. `MAX(sets.weight)` rend NULL,
 * le pre-calcul en faisait un 0.0, donc une entree du tableau, donc un
 * `current_value` ecrase a zero — tandis que le chemin individuel ne touchait a
 * rien. Mesure avant correctif : 0 contre 50.
 *
 * Le cas avec poids est la pour que le test ne passe pas simplement parce que
 * les deux chemins ne feraient plus rien.
 */
it('donne la même valeur par les deux chemins', function (?float $poids, float $attendu): void {
    [$user, $exercise] = utilisateurAvecObjectifDePoids();
    seanceAvecPoids($user, $exercise, $poids);

    $service = app(GoalService::class);

    $groupe = objectifDePoids($user, $exercise);
    $service->syncGoals($user->refresh());
    $valeurGroupe = (float) Goal::query()->findOrFail($groupe->id)->current_value;

    $groupe->delete();

    $seul = objectifDePoids($user, $exercise);
    $service->updateGoalProgress($seul);

    // Lu en memoire, pas en base : `updateGoalProgress()` ne persiste rien, c'est
    // son appelant qui enregistre. `syncGoals()`, lui, ecrit par un upsert
    // groupe — d'ou la lecture asymetrique des deux cotes.
    $valeurSeule = (float) $seul->current_value;

    expect($valeurGroupe)->toBe($attendu)
        ->and($valeurSeule)->toBe($attendu);
})->with([
    'série sans poids : la valeur ne bouge pas' => [null, 50.0],
    'série pesée : la valeur suit le maximum' => [80.0, 80.0],
]);

/**
 * Le pre-calcul doit tenir sa promesse : un nombre de requetes borne.
 *
 * C'est sa seule raison d'etre — a valeurs egales avec le chemin de repli, il
 * n'existe que pour ne pas interroger la base une fois par objectif. Trois
 * mutants disaient que `preCalculateMetrics`, `preCalculateMaxWeights` et
 * `preCalculateMaxVolumes` pouvaient rendre un tableau vide sans qu'aucun test
 * ne bronche : les valeurs restaient justes, chaque objectif repassant par sa
 * propre requete, et seul le nombre de requetes changeait.
 */
it('n’interroge pas la base une fois par objectif', function (): void {
    $user = User::factory()->create();

    // Huit objectifs de poids sur huit exercices distincts.
    foreach (range(1, 8) as $rang) {
        $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
        seanceAvecPoids($user, $exercise, 40.0 + $rang);
        objectifDePoids($user, $exercise);
    }

    $lectures = 0;

    DB::listen(function (QueryExecuted $requete) use (&$lectures): void {
        if (Str::startsWith(Str::lower($requete->sql), 'select')) {
            $lectures++;
        }
    });

    app(GoalService::class)->syncGoals($user->refresh());

    // Sans pré-calcul, ce serait au moins une lecture par objectif, plus le reste.
    // La borne est large à dessein : elle attrape la disparition du pré-calcul
    // sans se casser au premier `with()` ajouté ailleurs.
    expect($lectures)->toBeLessThan(8);
});
