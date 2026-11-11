<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Support\Carbon;

/*
 * Ce que `syncGoals()` lie dans sa requête, ce sont les attributs BRUTS de
 * l'objectif : un `current_value` resté texte (la base rend les décimaux en
 * chaîne) partirait tel quel. Chaque chemin de lecture doit donc poser un
 * flottant, ce que ni la lecture castée du modèle ni l'égalité souple ne
 * distinguent.
 */

/**
 * La valeur brute que l'objectif écrirait, sans le cast du modèle.
 */
function objectifValeurBruteEcrite(Goal $objectif): mixed
{
    return $objectif->getAttributes()['current_value'];
}

/**
 * Un objectif de force ou de volume, sans valeur courante utile, avec la
 * donnée que son chemin individuel lit en base.
 */
function objectifLuEnBase(GoalType $type): Goal
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    if ($type === GoalType::Weight) {
        PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'value' => 82.5,
        ]);
    } else {
        $seance = Workout::factory()->create(['user_id' => $user->id]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
        Set::factory()->create([
            'workout_line_id' => $ligne->id,
            'weight' => 82.5,
            'reps' => 2,
            'is_completed' => true,
        ]);
    }

    return Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercice->id,
        'type' => $type,
        'start_value' => 20,
        'target_value' => 500,
        'current_value' => 20,
    ]);
}

it('pose un flottant sur la valeur lue en base pour une charge et un volume', function (GoalType $type, float $attendu): void {
    $objectif = objectifLuEnBase($type);

    app(GoalService::class)->updateGoalProgress($objectif);

    expect(objectifValeurBruteEcrite($objectif))->toBe($attendu);
})->with([
    'charge' => [GoalType::Weight, 82.5],
    'volume' => [GoalType::Volume, 165.0],
]);

it('pose un flottant sur la dernière pesée lue en base', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'measured_at' => now()->subDay(), 'weight' => 71.25]);

    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 80,
        'target_value' => 70,
        'current_value' => 80,
    ]);

    app(GoalService::class)->updateGoalProgress($objectif);

    expect(objectifValeurBruteEcrite($objectif))->toBe(71.25);
});

it('pose un flottant sur la dernière mesure d’une partie du corps, convertie du pouce en centimètres', function (string $unite, float $attendu): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 10,
        'unit' => $unite,
        'measured_at' => now(),
    ]);

    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Measurement,
        'measurement_type' => 'Waist',
        'start_value' => 90,
        'target_value' => 80,
        'current_value' => 90,
    ]);

    app(GoalService::class)->updateGoalProgress($objectif);

    expect(objectifValeurBruteEcrite($objectif))->toBe($attendu);
})->with([
    'en centimètres' => ['cm', 10.0],
    'en pouces' => ['in', 25.4],
]);

it('garde la date d’un objectif atteint qui le reste', function (): void {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $user = User::factory()->create();

    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'target_value' => 3,
        'current_value' => 5,
        'completed_at' => Carbon::parse('2026-10-01 08:00:00'),
    ]);

    app(GoalService::class)->updateGoalProgress($objectif, ['seances_faites' => 5]);

    expect($objectif->completed_at?->toDateTimeString())->toBe('2026-10-01 08:00:00');

    Carbon::setTestNow();
});

it('rouvre un objectif dont le critère n’est plus rempli', function (): void {
    $user = User::factory()->create();

    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'target_value' => 3,
        'current_value' => 5,
        'completed_at' => Carbon::parse('2026-10-01 08:00:00'),
    ]);

    app(GoalService::class)->updateGoalProgress($objectif, ['seances_faites' => 1]);

    expect($objectif->completed_at)->toBeNull();
});
