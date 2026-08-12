<?php

declare(strict_types=1);

use App\Actions\FetchWorkoutTemplatesAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Carbon;

it('fetches only the workout templates for the given user', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    WorkoutTemplate::factory()->count(2)->create(['user_id' => $user1->id]);
    WorkoutTemplate::factory()->count(3)->create(['user_id' => $user2->id]);

    $action = app(FetchWorkoutTemplatesAction::class);
    $result = $action->execute($user1);

    expect($result)->toHaveCount(2);
    expect($result->pluck('user_id')->unique()->first())->toBe($user1->id);
});

it('orders workout templates chronologically by newest first', function (): void {
    $user = User::factory()->create();

    $template1 = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'created_at' => Carbon::now()->subDays(2),
    ]);

    $template2 = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'created_at' => Carbon::now(),
    ]);

    $template3 = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'created_at' => Carbon::now()->subDays(1),
    ]);

    $action = app(FetchWorkoutTemplatesAction::class);
    $result = $action->execute($user);

    expect($result)->toHaveCount(3);

    $ordered = $result->all();

    expect($ordered[0]->id)->toBe($template2->id);
    expect($ordered[1]->id)->toBe($template3->id);
    expect($ordered[2]->id)->toBe($template1->id);
});

it('loads the correct line counts and eager loads up to 3 exercises', function (): void {
    $user = User::factory()->create();

    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $exercises = Exercise::factory()->count(4)->create();

    // Create 4 lines
    foreach ($exercises as $index => $exercise) {
        $line = WorkoutTemplateLine::factory()->create([
            'workout_template_id' => $template->id,
            'exercise_id' => $exercise->id,
            'order' => $index,
        ]);

        // Create sets for lines
        WorkoutTemplateSet::factory()->count($index + 1)->create([
            'workout_template_line_id' => $line->id,
        ]);
    }

    $action = app(FetchWorkoutTemplatesAction::class);
    $result = $action->execute($user);

    expect($result)->toHaveCount(1);

    $fetchedTemplate = $result->firstOrFail();

    // Check line counts
    expect($fetchedTemplate->workout_template_lines_count)->toBe(4);

    // Check eager loaded lines limit
    expect($fetchedTemplate->workoutTemplateLines)->toHaveCount(3);

    // Check correct fields on nested relations and counts
    $firstLine = $fetchedTemplate->workoutTemplateLines->firstOrFail();

    expect($firstLine->workout_template_sets_count)->toBe(1);

    // Read the relation as eager loaded rather than re-querying it: the last
    // assertion below checks exactly which columns the eager load selected.
    $loadedExercise = $firstLine->exercise;

    if ($loadedExercise === null) {
        throw new RuntimeException('The eager-loaded template line carries no exercise.');
    }

    $expectedExercise = $exercises->firstOrFail();

    expect($loadedExercise->id)->toBe($expectedExercise->id);
    expect($loadedExercise->name)->toBe($expectedExercise->name);
    // Ensure only requested columns are loaded on exercise
    expect(array_keys($loadedExercise->getAttributes()))->toEqualCanonicalizing(['id', 'name']);
});
