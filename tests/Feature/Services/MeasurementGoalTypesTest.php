<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Http\Controllers\GoalController;
use App\Models\BodyMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

it('n’offre que des mensurations que la base porte réellement', function (): void {
    $colonnes = ['weight', 'body_fat'];

    expect(GoalController::measurementTypeValues())->toBe($colonnes);
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
