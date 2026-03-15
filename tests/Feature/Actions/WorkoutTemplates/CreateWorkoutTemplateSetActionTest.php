<?php

declare(strict_types=1);

use App\Actions\WorkoutTemplates\CreateWorkoutTemplateSetAction;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;

it('creates a workout template set with explicitly provided order', function (): void {
    $line = WorkoutTemplateLine::factory()->create();

    $data = [
        'reps' => 10,
        'weight' => 50,
        'order' => 5,
        'workout_template_line_id' => $line->id,
    ];

    $action = app(CreateWorkoutTemplateSetAction::class);
    $set = $action->execute($line, $data);

    expect($set)->toBeInstanceOf(WorkoutTemplateSet::class)
        ->and($set->reps)->toBe(10)
        ->and($set->weight)->toEqual(50.0)
        ->and($set->order)->toBe(5)
        ->and($set->workout_template_line_id)->toBe($line->id);
});

it('auto-calculates order as 0 when no sets exist', function (): void {
    $line = WorkoutTemplateLine::factory()->create();

    $data = [
        'reps' => 8,
        'weight' => 60,
    ];

    $action = app(CreateWorkoutTemplateSetAction::class);
    $set = $action->execute($line, $data);

    expect($set->order)->toBe(0);
});

it('auto-calculates order by incrementing max order when sets exist', function (): void {
    $line = WorkoutTemplateLine::factory()->create();

    // Create an existing set with order 2
    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line->id,
        'order' => 2,
    ]);

    $data = [
        'reps' => 5,
        'weight' => 100,
    ];

    $action = app(CreateWorkoutTemplateSetAction::class);
    $set = $action->execute($line, $data);

    // Max order was 2, so the new one should be 3
    expect($set->order)->toBe(3);
});

it('ignores workout_template_line_id from data array to prevent mass assignment mismatch', function (): void {
    // Create two lines
    $line1 = WorkoutTemplateLine::factory()->create();
    $line2 = WorkoutTemplateLine::factory()->create();

    $data = [
        'reps' => 12,
        'weight' => 20,
        'workout_template_line_id' => $line2->id, // Maliciously try to assign to different line
    ];

    $action = app(CreateWorkoutTemplateSetAction::class);

    // Execute on line 1
    $set = $action->execute($line1, $data);

    // The set should belong to line 1 despite the payload saying line 2
    expect($set->workout_template_line_id)->toBe($line1->id)
        ->and($set->workout_template_line_id)->not->toBe($line2->id);
});
