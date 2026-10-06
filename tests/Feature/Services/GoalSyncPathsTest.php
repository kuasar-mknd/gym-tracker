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
 * Un utilisateur et un exercice de force.
 *
 * @return array{0: User, 1: Exercise}
 */
function utilisateurAvecObjectifDePoids(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    return [$user, $exercise];
}

/**
 * Un objectif de charge (ou de volume) parti de 20, a 50 sur 100 : trois
 * valeurs distinctes, pour qu'un retour a zero, au depart ou a la derniere
 * valeur se distinguent.
 */
function objectifDePoids(User $user, Exercise $exercise, float $courant = 50, GoalType $type = GoalType::Weight): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => $type,
        'start_value' => 20,
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
 * Depuis #1953, une serie sans poids ne soutient pas l'objectif : les deux
 * chemins le ramenent a sa valeur de depart, 20, au lieu de garder 50, la
 * derniere valeur, qu'une serie supprimee depuis pouvait avoir laissee.
 *
 * Le cas avec poids est la pour que le test ne passe pas simplement parce que
 * les deux chemins ne feraient plus rien.
 */
it('donne la même valeur par les deux chemins', function (GoalType $type, ?float $poids, float $attendu): void {
    [$user, $exercise] = utilisateurAvecObjectifDePoids();
    seanceAvecPoids($user, $exercise, $poids);

    $service = app(GoalService::class);

    $groupe = objectifDePoids($user, $exercise, type: $type);
    $service->syncGoals($user->refresh());
    $valeurGroupe = (float) Goal::query()->findOrFail($groupe->id)->current_value;

    $groupe->delete();

    $seul = objectifDePoids($user, $exercise, type: $type);
    $service->updateGoalProgress($seul);

    // Lu en memoire, pas en base : `updateGoalProgress()` ne persiste rien, c'est
    // son appelant qui enregistre. `syncGoals()`, lui, ecrit par une mise a
    // jour groupee — d'ou la lecture asymetrique des deux cotes.
    $valeurSeule = (float) $seul->current_value;

    expect($valeurGroupe)->toBe($attendu)
        ->and($valeurSeule)->toBe($attendu);
})->with([
    'charge, série sans poids : la valeur revient au départ' => [GoalType::Weight, null, 20.0],
    'charge, série pesée : la valeur suit le maximum' => [GoalType::Weight, 80.0, 80.0],
    'volume, série sans poids : la valeur revient au départ' => [GoalType::Volume, null, 20.0],
    'volume, série pesée : la valeur suit la meilleure séance' => [GoalType::Volume, 80.0, 800.0],
]);

/**
 * L'écriture groupée doit remonter `updated_at`, sinon la ligne ment sur sa
 * fraîcheur.
 *
 * `syncGoals` écrit tous les objectifs modifiés en une seule mise à jour, qui
 * court-circuite le chemin d'enregistrement ordinaire : rien ne pose la date
 * pour elle. C'était un `Goal::upsert(...)`, auquel Eloquent ajoutait la
 * colonne de lui-même (`addUpdatedAtToUpsertColumns`) ; l'upsert pouvait
 * réinsérer un objectif supprimé pendant le recalcul (#1985), et la mise à jour
 * qui le remplace nomme `updated_at` elle-même.
 *
 * Ce test verrouille une propriété que rien d'autre ne vérifie : la ligne ne
 * doit pas rester figée à sa date de création pendant que sa progression
 * change. Il tomberait si l'écriture oubliait les horodatages.
 */
it('remonte la date de modification quand la progression change', function (): void {
    $this->freezeTime();

    $user = User::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 5,
        'completed_at' => null,
    ]);

    $avant = $goal->refresh()->updated_at;

    $this->travel(1)->days();

    // L'observateur de séance déclenche la synchronisation : la progression passe
    // de 0 à 1, l'objectif est donc sale et part dans l'écriture groupée.
    Workout::factory()->create(['user_id' => $user->id]);

    $apres = $goal->refresh();

    expect((float) $apres->current_value)->toBe(1.0)
        ->and($apres->updated_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($apres->updated_at?->toDateTimeString())->not->toBe($avant?->toDateTimeString());
});
