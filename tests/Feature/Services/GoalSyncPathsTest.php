<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
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
 * Un objectif de mensuration lu dans les pesées, parti de 20, à 50 sur 100 :
 * les trois mêmes valeurs distinctes que pour la charge.
 */
function objectifDePeseeSuivi(User $user, string $colonne): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Measurement,
        'measurement_type' => $colonne,
        'start_value' => 20,
        'target_value' => 100,
        'current_value' => 50,
    ]);
}

/**
 * Le poids de corps et la masse grasse, par les deux chemins (#2107).
 *
 * Les deux lisaient la dernière pesée, qu'elle porte ou non la colonne visée,
 * et ne touchaient à rien quand elle n'en portait pas. Plus aucune pesée, ou
 * une dernière pesée sans masse grasse, laissaient donc l'objectif à 50 : la
 * valeur d'une pesée supprimée depuis, comme un objectif de charge avant #1953.
 *
 * Les deux chemins lisent maintenant la dernière pesée qui PORTE la colonne,
 * et reviennent à la valeur de départ, 20, quand il n'y en a aucune. Les
 * pesées sont posées avant l'objectif : celles que l'observateur recalcule en
 * les enregistrant ne le connaissent pas encore.
 *
 * La dernière se lit par sa date, pas par l'ordre de saisie : une pesée
 * oubliée, saisie après coup à sa date passée, ne passe pas devant une pesée
 * plus récente. Ailleurs, les pesées sont saisies dans l'ordre de leurs dates,
 * et un tri par le seul identifiant passerait aussi.
 */
it('donne la même valeur de pesée par les deux chemins', function (string $colonne, array $pesees, float $attendu): void {
    /** @var list<array{0: string, 1: float, 2: float|null}> $pesees date, poids, masse grasse */
    $user = User::factory()->create();

    foreach ($pesees as [$le, $poids, $masseGrasse]) {
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'measured_at' => $le,
            'weight' => $poids,
            'body_fat' => $masseGrasse,
        ]);
    }

    $service = app(GoalService::class);

    $groupe = objectifDePeseeSuivi($user, $colonne);
    $service->syncGoals($user->refresh());
    $valeurGroupe = (float) Goal::query()->findOrFail($groupe->id)->current_value;

    $groupe->delete();

    $seul = objectifDePeseeSuivi($user, $colonne);
    $service->updateGoalProgress($seul);

    expect($valeurGroupe)->toBe($attendu)
        ->and((float) $seul->current_value)->toBe($attendu);
})->with([
    'poids de corps, aucune pesée : la valeur revient au départ' => ['weight', [], 20.0],
    'masse grasse, aucune pesée n’en porte : la valeur revient au départ' => ['body_fat', [['2026-06-10', 80.0, null]], 20.0],
    'masse grasse, la dernière pesée n’en porte pas : la valeur suit la dernière qui en porte' => [
        'body_fat',
        [['2026-06-10', 81.0, 30.0], ['2026-06-15', 80.0, null]],
        30.0,
    ],
    'poids de corps, une pesée antidatée saisie en dernier : la valeur suit la plus récente par sa date' => [
        'weight',
        [['2026-06-15', 80.0, 18.0], ['2026-06-10', 84.0, 22.0]],
        80.0,
    ],
    'masse grasse, une pesée antidatée saisie en dernier : la valeur suit la plus récente par sa date' => [
        'body_fat',
        [['2026-06-15', 80.0, 18.0], ['2026-06-10', 84.0, 22.0]],
        18.0,
    ],
]);

/**
 * Un objectif ne lit que les pesées de son compte, par les deux chemins.
 *
 * La lecture part de la relation du compte (`dernierePeseeQuiPorte()`), qui
 * pose le propriétaire, puis s'en détache pour servir de sous-requête au lot.
 * Écrite depuis le modèle (`BodyMeasurement::query()`), la forme habituelle
 * d'une sous-requête, elle perdrait ce filtre sans qu'aucun autre test ne
 * tombe : chacun n'a qu'un compte qui pèse. Ici, l'autre compte a la pesée la
 * plus récente et le plus grand identifiant, celle que lirait une requête sans
 * propriétaire.
 */
it('ne lit que les pesées du compte de l’objectif, par les deux chemins', function (string $colonne, float $attendu): void {
    $user = User::factory()->create();
    $autreCompte = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'measured_at' => '2026-06-10',
        'weight' => 84.0,
        'body_fat' => 25.0,
    ]);

    BodyMeasurement::factory()->create([
        'user_id' => $autreCompte->id,
        'measured_at' => '2026-06-15',
        'weight' => 60.0,
        'body_fat' => 10.0,
    ]);

    $service = app(GoalService::class);

    $groupe = objectifDePeseeSuivi($user, $colonne);
    $service->syncGoals($user->refresh());
    $valeurGroupe = (float) Goal::query()->findOrFail($groupe->id)->current_value;

    $groupe->delete();

    $seul = objectifDePeseeSuivi($user, $colonne);
    $service->updateGoalProgress($seul);

    expect($valeurGroupe)->toBe($attendu)
        ->and((float) $seul->current_value)->toBe($attendu);
})->with([
    'poids de corps' => ['weight', 84.0],
    'masse grasse' => ['body_fat', 25.0],
]);

/**
 * Un objectif de partie du corps, parti de 20, à 50 sur 100 : les trois mêmes
 * valeurs distinctes que pour la charge.
 */
function objectifDePartieSuivie(User $user, string $partie): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Measurement,
        'measurement_type' => $partie,
        'start_value' => 20,
        'target_value' => 100,
        'current_value' => 50,
    ]);
}

/**
 * Les parties du corps, par les deux chemins (#2131).
 *
 * Le lot lisait chaque partie par la requête du chemin d'un seul objectif,
 * une par objectif : les deux chemins ne pouvaient pas diverger, ils
 * faisaient la même chose. Le lot lit désormais toutes les parties suivies en
 * une instruction, une sous-requête par partie : c'est la même requête
 * (`derniereMesureDeLaPartie()`), et ces cas tiennent l'accord sur chaque
 * façon de lire une mesure. La dernière se lit par sa date puis par l'ordre de
 * saisie (deux mesures du même jour), la saisie en pouces se ramène en
 * centimètres, une partie sans mesure revient au départ, et la collation de la
 * colonne rapproche « waist » de « Waist », sans `lower()`.
 *
 * Les mesures sont posées avant l'objectif : celles que l'observateur
 * recalcule en les enregistrant ne le connaissent pas encore.
 */
it('donne la même valeur de partie du corps par les deux chemins', function (string $partie, array $mesures, float $attendu): void {
    /** @var list<array{0: string, 1: float, 2: string, 3: string}> $mesures partie, valeur, unité, date */
    $user = User::factory()->create();

    foreach ($mesures as [$partieMesuree, $valeur, $unite, $le]) {
        BodyPartMeasurement::factory()->create([
            'user_id' => $user->id,
            'part' => $partieMesuree,
            'value' => $valeur,
            'unit' => $unite,
            'measured_at' => $le,
        ]);
    }

    $service = app(GoalService::class);

    $groupe = objectifDePartieSuivie($user, $partie);
    $service->syncGoals($user->refresh());
    $valeurGroupe = (float) Goal::query()->findOrFail($groupe->id)->current_value;

    $groupe->delete();

    $seul = objectifDePartieSuivie($user, $partie);
    $service->updateGoalProgress($seul);

    expect($valeurGroupe)->toBe($attendu)
        ->and((float) $seul->current_value)->toBe($attendu);
})->with([
    'une partie mesurée : la valeur suit la dernière mesure' => [
        'Waist',
        [['Waist', 88.0, 'cm', '2026-06-01'], ['Waist', 85.0, 'cm', '2026-06-15']],
        85.0,
    ],
    'une partie jamais mesurée : la valeur revient au départ' => [
        'Waist',
        [['Chest', 101.0, 'cm', '2026-06-15']],
        20.0,
    ],
    'une mesure en pouces : la valeur est ramenée en centimètres' => [
        'Waist',
        [['Waist', 32.0, 'in', '2026-06-15']],
        81.28,
    ],
    'deux mesures du même jour : la valeur suit la dernière saisie' => [
        'Waist',
        [['Waist', 86.0, 'cm', '2026-06-15'], ['Waist', 84.0, 'cm', '2026-06-15']],
        84.0,
    ],
    'une mesure antidatée saisie en dernier : la valeur suit la plus récente par sa date' => [
        'Waist',
        [['Waist', 84.0, 'cm', '2026-06-15'], ['Waist', 88.0, 'cm', '2026-06-10']],
        84.0,
    ],
    'une partie nommée dans une autre casse : la collation les rapproche' => [
        'waist',
        [['Waist', 83.0, 'cm', '2026-06-15']],
        83.0,
    ],
]);

/**
 * Un objectif de partie du corps ne lit que les mesures de son compte, par
 * les deux chemins.
 *
 * Même raison que pour les pesées : la lecture part de la relation du compte,
 * puis s'en détache pour servir de sous-requête au lot. L'autre compte a la
 * mesure la plus récente et le plus grand identifiant, celle que lirait une
 * requête sans propriétaire.
 */
it('ne lit que les mesures de partie du corps du compte de l’objectif, par les deux chemins', function (): void {
    $user = User::factory()->create();
    $autreCompte = User::factory()->create();

    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 84.0,
        'unit' => 'cm',
        'measured_at' => '2026-06-10',
    ]);

    BodyPartMeasurement::factory()->create([
        'user_id' => $autreCompte->id,
        'part' => 'Waist',
        'value' => 60.0,
        'unit' => 'cm',
        'measured_at' => '2026-06-15',
    ]);

    $service = app(GoalService::class);

    $groupe = objectifDePartieSuivie($user, 'Waist');
    $service->syncGoals($user->refresh());
    $valeurGroupe = (float) Goal::query()->findOrFail($groupe->id)->current_value;

    $groupe->delete();

    $seul = objectifDePartieSuivie($user, 'Waist');
    $service->updateGoalProgress($seul);

    expect($valeurGroupe)->toBe(84.0)
        ->and((float) $seul->current_value)->toBe(84.0);
});

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
