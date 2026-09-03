<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Http\Controllers\GoalController;
use App\Models\BodyMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;

/*
 * L'interface proposait cinq mensurations pour un objectif, dont trois qui
 * n'existent pas comme colonnes de `body_measurements`.
 *
 * `updateMeasurementGoal` lit une COLONNE de cette table. Un objectif sur le
 * tour de taille rendait donc :
 *
 *   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'waist'
 *
 * Le calcul se declenche a chaque pesee enregistree (SyncUserGoals) et a
 * l'ouverture de la page des objectifs : l'objectif etait casse des sa creation,
 * et il emportait la page avec lui (#1454).
 */

/*
 * Le garde comparait la liste offerte aux deux COLONNES de `body_measurements`.
 * Depuis #1454, les parties du corps se lisent aussi — dans une autre table, par
 * une autre requete. Comparer a une liste figee ne dirait plus rien de juste.
 *
 * Le contrat, lui, n'a pas change : toute option offerte doit etre LISIBLE.
 * Alors on l'eprouve, option par option, plutot que de l'affirmer.
 */
it('sait lire chacune des mensurations qu’elle propose', function (): void {
    $proprietaire = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $proprietaire->id,
        'weight' => 85,
        'body_fat' => 22,
        'measured_at' => now(),
    ]);

    foreach (\App\Models\BodyPartMeasurement::COMMON_PARTS as $partie) {
        \App\Models\BodyPartMeasurement::factory()->create([
            'user_id' => $proprietaire->id,
            'part' => $partie,
            'value' => 42,
            'unit' => 'cm',
            'measured_at' => now(),
        ]);
    }

    $attendus = ['weight' => 85.0, 'body_fat' => 22.0];

    foreach (GoalController::measurementTypeValues() as $type) {
        $objectif = Goal::factory()->create([
            'user_id' => $proprietaire->id,
            'type' => GoalType::Measurement,
            'measurement_type' => $type,
            'start_value' => 1,
            'current_value' => 1,
            'target_value' => 1000,
            'completed_at' => null,
        ]);

        app(GoalService::class)->updateGoalProgress($objectif);

        // 1 serait « rien lu » : chaque option doit ramener sa mesure.
        expect($objectif->current_value)
            ->toBe($attendus[$type] ?? 42.0, "« {$type} » n’a pas été lue");
    }
});

it('refuse un objectif sur une mensuration inexistante', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Tour de taille',
            'type' => 'measurement',
            'measurement_type' => 'waist',
            'target_value' => 80,
        ])
        ->assertSessionHasErrors('measurement_type');

    expect(Goal::count())->toBe(0);
});

/**
 * Les objectifs deja crees restent en base : le garde du service est ce qui
 * empeche la page de casser pour eux.
 */
it('laisse un objectif sur une mensuration inconnue sans progression, sans planter', function (): void {
    $user = User::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'waist',
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 85, 'measured_at' => now()]);

    app(GoalService::class)->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(90.0)
        ->and($goal->completed_at)->toBeNull();
});

it('suit toujours la masse grasse, qui existe bien', function (): void {
    $user = User::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'body_fat',
        'start_value' => 30,
        'current_value' => 30,
        'target_value' => 20,
        'completed_at' => null,
    ]);

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'body_fat' => 22,
        'measured_at' => now(),
    ]);

    app(GoalService::class)->updateGoalProgress($goal);

    // 22, pas 85 : le ternaire qui choisit la colonne doit lire body_fat.
    expect($goal->current_value)->toBe(22.0);
});
