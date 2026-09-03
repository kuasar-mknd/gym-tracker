<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Models\Workout;
use App\Services\GoalService;

/*
 * #1501 : deux défauts distincts, tous deux visibles par l'utilisateur.
 *
 * Le premier : la progression se comptait en valeur absolue des deux côtés,
 * donc s'éloigner du but comptait comme s'en approcher. Le second : un objectif
 * atteint n'était plus jamais réévalué, la synchronisation ne chargeant que les
 * objectifs non terminés.
 */

/**
 * Un objectif de perte de poids : je pèse 80, je vise 75.
 */
function objectifDePerteDePoids(User $user, float $actuel): Goal
{
    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => $actuel,
        'measured_at' => now(),
    ]);

    return Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 80,
        'target_value' => 75,
        'current_value' => $actuel,
        'completed_at' => null,
    ]);
}

it('n’annonce aucune progression quand on s’éloigne du but', function (): void {
    $user = User::factory()->create();
    $goal = objectifDePerteDePoids($user, 85);

    app(GoalService::class)->updateGoalProgress($goal);

    expect($goal->progress_pct)->toBe(0.0);
});

it('compte la progression dans le sens de la cible', function (): void {
    $user = User::factory()->create();

    // Parti de 80, visant 75, arrivé à 77,5 : la moitié du chemin.
    $goal = objectifDePerteDePoids($user, 77.5);

    app(GoalService::class)->updateGoalProgress($goal);

    expect($goal->progress_pct)->toBe(50.0);
});

it('ne dépasse pas cent quand on va au-delà de la cible', function (): void {
    $user = User::factory()->create();
    $goal = objectifDePerteDePoids($user, 70);

    app(GoalService::class)->updateGoalProgress($goal);

    expect($goal->progress_pct)->toBe(100.0);
});

/*
 * L'autre moitié : un objectif atteint doit pouvoir cesser de l'être.
 */
it('dé-marque un objectif dont le fait sous-jacent a reculé', function (): void {
    $user = User::factory()->create();

    Workout::factory()->count(2)->create(['user_id' => $user->id]);

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'target_value' => 5,
        'current_value' => 5,
        'completed_at' => now(),
    ]);

    app(GoalService::class)->syncGoals($user);

    // Deux séances en base, une cible à cinq : le critère n'est plus rempli.
    expect($goal->refresh()->completed_at)->toBeNull()
        ->and($goal->current_value)->toBe(2.0);
});

it('laisse marqué un objectif que les faits soutiennent encore', function (): void {
    $user = User::factory()->create();

    Workout::factory()->count(7)->create(['user_id' => $user->id]);

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'target_value' => 5,
        'current_value' => 5,
        'completed_at' => now(),
    ]);

    app(GoalService::class)->syncGoals($user);

    // Sans quoi la correction ci-dessus dé-marquerait tout le monde.
    expect($goal->refresh()->completed_at)->not->toBeNull();
});
