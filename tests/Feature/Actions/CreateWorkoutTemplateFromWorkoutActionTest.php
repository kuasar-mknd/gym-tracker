<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Carbon;

it('creates a template correctly from a workout', function (): void {
    $user = User::factory()->create();

    // Create a workout with a specific created_at date to check the description formatting
    $workoutDate = Carbon::create(2023, 10, 15, 14, 30, 0);
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Awesome Workout',
        'created_at' => $workoutDate,
    ]);

    // Create 2 workout lines
    $line1 = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'order' => 1,
    ]);

    $line2 = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'order' => 2,
    ]);

    // Create sets for line 1
    $set1_1 = Set::factory()->create([
        'workout_line_id' => $line1->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => true,
    ]);

    $set1_2 = Set::factory()->create([
        'workout_line_id' => $line1->id,
        'reps' => 8,
        'weight' => 60,
        'is_warmup' => false,
    ]);

    // Create set for line 2
    $set2_1 = Set::factory()->create([
        'workout_line_id' => $line2->id,
        'reps' => 12,
        'weight' => 30,
        'is_warmup' => false,
    ]);

    $action = app(CreateWorkoutTemplateFromWorkoutAction::class);
    $template = $action->execute($user, $workout);

    // Assert Template is created correctly
    expect($template)
        ->toBeInstanceOf(WorkoutTemplate::class)
        ->user_id->toBe($user->id)
        ->name->toBe('My Awesome Workout (Modèle)')
        ->description->toBe('Créé à partir de la séance du 15/10/2023');

    // Load relationships for assertions
    $template->load('workoutTemplateLines.workoutTemplateSets');

    // Assert Template Lines are created correctly
    expect($template->workoutTemplateLines)->toHaveCount(2);

    // Line 1 assertions
    $templateLine1 = $template->workoutTemplateLines->where('order', 1)->first();
    expect($templateLine1)
        ->not->toBeNull()
        ->exercise_id->toBe($line1->exercise_id);

    expect($templateLine1->workoutTemplateSets)->toHaveCount(2);

    // Line 1, Set 1 assertions (Warmup)
    $templateSet1_1 = $templateLine1->workoutTemplateSets->sortBy('id')->first();
    expect($templateSet1_1)
        ->not->toBeNull()
        ->reps->toBe(10)
        ->weight->toEqual(50.5)
        ->is_warmup->toBeTrue();

    // Line 1, Set 2 assertions
    $templateSet1_2 = $templateLine1->workoutTemplateSets->sortBy('id')->skip(1)->first();
    expect($templateSet1_2)
        ->not->toBeNull()
        ->reps->toBe(8)
        ->weight->toEqual(60.0)
        ->is_warmup->toBeFalse();

    // Line 2 assertions
    $templateLine2 = $template->workoutTemplateLines->where('order', 2)->first();
    expect($templateLine2)
        ->not->toBeNull()
        ->exercise_id->toBe($line2->exercise_id);

    expect($templateLine2->workoutTemplateSets)->toHaveCount(1);

    // Line 2, Set 1 assertions
    $templateSet2_1 = $templateLine2->workoutTemplateSets->first();
    expect($templateSet2_1)
        ->not->toBeNull()
        ->reps->toBe(12)
        ->weight->toEqual(30.0)
        ->is_warmup->toBeFalse();
});

it('handles missing created_at when formatting description', function (): void {
    $user = User::factory()->create();

    // Create a workout with a null created_at date
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'Legacy Workout',
    ]);
    // Force created_at to be null, as factories usually auto-populate it
    $workout->timestamps = false;
    $workout->created_at = null;
    $workout->save();

    $action = app(CreateWorkoutTemplateFromWorkoutAction::class);
    $template = $action->execute($user, $workout);

    // The logic falls back to now()->format('d/m/Y')
    $expectedDate = now()->format('d/m/Y');

    // Assert Template is created correctly with fallback date
    expect($template)
        ->toBeInstanceOf(WorkoutTemplate::class)
        ->user_id->toBe($user->id)
        ->name->toBe('Legacy Workout (Modèle)')
        ->description->toBe('Créé à partir de la séance du '.$expectedDate);
});
