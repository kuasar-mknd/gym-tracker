<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->service = app(GoalService::class);
});

test('syncGoals updates multiple dirty goals and ignores completed ones', function (): void {
    $user = User::factory()->create();

    // Goal 1: Frequency goal (incomplete)
    $goal1 = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 5,
        'completed_at' => null,
    ]);

    // Goal 2: Completed goal
    $goal2 = Goal::factory()->completed()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'start_value' => 0,
        'current_value' => 10,
        'target_value' => 10,
    ]);

    // Simulate 3 workouts
    Workout::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    $this->service->syncGoals($user);

    $goal1->refresh();
    $goal2->refresh();

    // Goal 1 should be updated (current_value: 3, progress_pct: 60)
    expect($goal1->current_value)->toBe(3.0)
        ->and($goal1->progress_pct)->toBe(60.0)
        ->and($goal1->completed_at)->toBeNull();

    /*
     * L'objectif 2 est REVU, et de-marque.
     *
     * Ce test affirmait « Goal 2 should be untouched » : la synchronisation ne
     * chargeait que les objectifs non termines, donc un objectif atteint le
     * restait quoi qu'il arrive. C'etait la limitation, pas la regle — la
     * moitie de `checkCompletion()` qui de-marque etait inatteignable (#1501).
     *
     * Trois seances en base, une cible a dix : le critere n'est plus rempli, et
     * l'objectif cesse d'etre marque atteint.
     */
    expect($goal2->current_value)->toBe(3.0)
        ->and($goal2->completed_at)->toBeNull();
});

test('updateGoalProgress correctly updates weight goal', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Weight,
        'exercise_id' => $exercise->id,
        'start_value' => 50,
        'current_value' => 50,
        'target_value' => 100,
        'completed_at' => null,
    ]);

    // Workout with 80kg max
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 60]);
    Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 80]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(80.0)
        ->and($goal->progress_pct)->toBe(60.0) // (80 - 50) / (100 - 50) = 30 / 50 = 60%
        ->and($goal->completed_at)->toBeNull();
});

test('updateGoalProgress correctly updates volume goal directly in SQL', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Volume,
        'exercise_id' => $exercise->id,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 1000,
        'completed_at' => null,
    ]);

    // Workout 1: Volume = 50 * 10 + 60 * 5 = 500 + 300 = 800
    $workout1 = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $workoutLine1->id, 'weight' => 50, 'reps' => 10]);
    Set::factory()->create(['workout_line_id' => $workoutLine1->id, 'weight' => 60, 'reps' => 5]);

    // Workout 2: Volume = 80 * 8 + 80 * 8 = 640 + 640 = 1280
    $workout2 = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $workoutLine2->id, 'weight' => 80, 'reps' => 8]);
    Set::factory()->create(['workout_line_id' => $workoutLine2->id, 'weight' => 80, 'reps' => 8]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(1280.0)
        ->and((float) $goal->progress_pct)->toBe(100.0)
        ->and($goal->completed_at)->not->toBeNull();
});

test('updateGoalProgress correctly updates measurement goal (lower is better)', function (): void {
    $user = User::factory()->create();

    // Goal: lose weight from 90kg to 80kg
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => Carbon::now()->subDay(),
    ]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(85.0)
        ->and($goal->progress_pct)->toBe(50.0) // abs(85 - 90) / abs(80 - 90) = 5 / 10 = 50%
        ->and($goal->completed_at)->toBeNull();

    // Add new measurement achieving goal
    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 79,
        'measured_at' => Carbon::now(),
    ]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(79.0)
        ->and((float) $goal->progress_pct)->toBe(100.0) // progress maxes out at 100%
        ->and($goal->completed_at)->not->toBeNull();
});

test('checkCompletion reverts completion if target is no longer met', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Weight,
        'exercise_id' => $exercise->id,
        'start_value' => 50,
        'current_value' => 100,
        'target_value' => 100,
        'completed_at' => now(), // Already marked completed
    ]);

    // Change goal target higher so it's no longer complete
    $goal->target_value = 110;

    // Create a workout so maxWeight is found
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 100]);

    $this->service->updateGoalProgress($goal);

    expect($goal->completed_at)->toBeNull()
        ->and($goal->progress_pct)->toBeLessThan(100.0);
});

test('updateProgressPercentage handles edge cases', function (): void {
    $user = User::factory()->create();

    // Case 1: Start equals target
    $goal1 = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'start_value' => 5,
        'target_value' => 5,
        'current_value' => 3,
    ]);

    $this->service->updateGoalProgress($goal1);
    expect($goal1->progress_pct)->toBe(0.0);

    $goal1->current_value = 5;
    // Since updateFrequencyGoal overwrites current_value based on workouts,
    // we mock the goal type so it doesn't overwrite our manual current_value
    $goal1->type = \App\Enums\GoalType::Measurement;
    $goal1->measurement_type = 'weight'; // Just to pass guard
    $this->service->updateGoalProgress($goal1);
    expect((float) $goal1->progress_pct)->toBe(100.0);

    // Case 2: Progress capped at 100%
    $goal2 = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 0,
        'target_value' => 5,
        'current_value' => 10, // overshoot
    ]);

    \App\Models\BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 10,
    ]);

    $this->service->updateGoalProgress($goal2);
    expect((float) $goal2->progress_pct)->toBe(100.0);

    // Case 3: Progress floored at 0%
    $goal3 = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 80,
        'target_value' => 70, // lower is better
        'current_value' => 85, // gained weight instead
    ]);

    // create a measurement so current_value updates
    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);

    $this->service->updateGoalProgress($goal3);

    /*
     * Zero, comme le nom du cas l'annonce depuis le debut.
     *
     * Il affirmait 50 %, et son commentaire se raisonnait jusqu'a l'accepter :
     * « abs(85-80) / abs(70-80) = 50 %, la progression ne regarde pas le sens ».
     * C'etait vrai, et c'etait le defaut : je pese 80, je vise 75, je monte a
     * 85, et la barre annonce que j'ai fait la moitie du chemin (#1501).
     *
     * Le chemin se compte desormais dans le sens de la cible. S'eloigner du
     * depart donne un chemin negatif, donc zero apres plancher.
     */
    expect($goal3->progress_pct)->toBe(0.0);
});

test('guard clauses for missing exercise_id and measurement_type', function (): void {
    $user = User::factory()->create();

    // Weight goal missing exercise_id
    $goalWeight = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Weight,
        'exercise_id' => null,
        'current_value' => 0,
    ]);
    $this->service->updateGoalProgress($goalWeight);
    expect($goalWeight->current_value)->toBe(0.0);

    // Volume goal missing exercise_id
    $goalVolume = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Volume,
        'exercise_id' => null,
        'current_value' => 0,
    ]);
    $this->service->updateGoalProgress($goalVolume);
    expect($goalVolume->current_value)->toBe(0.0);

    // Measurement goal missing measurement_type
    $goalMeasurement = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => null,
        'current_value' => 0,
    ]);
    $this->service->updateGoalProgress($goalMeasurement);
    expect($goalMeasurement->current_value)->toBe(0.0);
});

/**
 * Un objectif de perte de poids sans mesure n'est pas un objectif atteint.
 *
 * `current_value` vaut 0 par defaut en base. Pour un objectif « descendre a
 * 80 kg », le critere est `current_value <= target_value` — et 0 <= 80. Le
 * garde `&& $goal->current_value > 0` est la seule chose qui empeche un
 * objectif tout juste cree d'etre annonce comme atteint avant meme la premiere
 * pesee.
 *
 * Rien ne l'assurait : la mutation `> 0` en `>= 0` survivait, et l'utilisateur
 * aurait vu « objectif atteint ! » le jour de sa creation.
 */
test('un objectif de perte de poids sans mesure n’est pas atteint', function (): void {
    $user = User::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 90,
        'current_value' => 0,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(0.0)
        ->and($goal->completed_at)->toBeNull();
});

/**
 * Et le sens de l'objectif se lit sur les valeurs, pas sur son type.
 *
 * « Descendre a 80 » et « monter a 100 » sont tous deux des objectifs de type
 * Measurement : c'est `target_value < start_value` qui les distingue, et les
 * deux conditions sont liees par un ET.
 *
 * Remplacer ce ET par un OU faisait basculer TOUT objectif de mesure dans la
 * branche « plus bas est mieux ». Un objectif de prise de poids aurait alors
 * ete annonce atteint tant que l'utilisateur restait EN DESSOUS de sa cible —
 * exactement l'inverse de ce qu'il demande. La mutation survivait.
 */
test('un objectif de prise de poids n’est pas atteint tant qu’on est en dessous', function (): void {
    $user = User::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'start_value' => 70,
        'current_value' => 70,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 75,
        'measured_at' => Carbon::now(),
    ]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(75.0)
        ->and($goal->completed_at)->toBeNull();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 81,
        'measured_at' => Carbon::now()->addHour(),
    ]);

    $this->service->updateGoalProgress($goal);

    expect($goal->current_value)->toBe(81.0)
        ->and($goal->completed_at)->not->toBeNull();
});
