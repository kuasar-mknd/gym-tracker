<?php

declare(strict_types=1);

use App\Actions\UpdateWorkoutTemplateAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;

it('updates workout template name and description', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
        'description' => 'Old Description',
    ]);

    $action = app(UpdateWorkoutTemplateAction::class);

    $updatedTemplate = $action->execute($template, [
        'name' => 'New Name',
        'description' => 'New Description',
    ]);

    expect($updatedTemplate->name)->toBe('New Name')
        ->and($updatedTemplate->description)->toBe('New Description');

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'name' => 'New Name',
        'description' => 'New Description',
    ]);
});

it('updates workout template and keeps old description if not provided', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
        'description' => 'Old Description',
    ]);

    $action = app(UpdateWorkoutTemplateAction::class);

    $updatedTemplate = $action->execute($template, [
        'name' => 'New Name',
    ]);

    expect($updatedTemplate->name)->toBe('New Name')
        ->and($updatedTemplate->description)->toBe('Old Description');

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'name' => 'New Name',
        'description' => 'Old Description',
    ]);
});

it('updates workout template lines and sets', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    $line1 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise1->id,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line1->id,
        'reps' => 10,
        'weight' => 50,
    ]);

    $action = app(UpdateWorkoutTemplateAction::class);

    $updatedTemplate = $action->execute($template, [
        'name' => 'New Name',
        'exercises' => [
            [
                'id' => $exercise2->id,
                'sets' => [
                    ['reps' => 8, 'weight' => 60, 'is_warmup' => false],
                    ['reps' => 6, 'weight' => 70, 'is_warmup' => true],
                ],
            ],
            [
                'id' => $exercise1->id,
                'sets' => [
                    ['reps' => 12, 'weight' => null, 'is_warmup' => false],
                ],
            ],
        ],
    ]);

    $this->assertDatabaseMissing('workout_template_lines', [
        'id' => $line1->id,
    ]);

    expect($updatedTemplate->workoutTemplateLines)->toHaveCount(2);

    // Verify first line
    $newLine1 = $updatedTemplate->workoutTemplateLines->firstWhere('exercise_id', $exercise2->id);
    expect($newLine1)->not->toBeNull()
        ->and($newLine1->order)->toBe(0)
        ->and($newLine1->workoutTemplateSets)->toHaveCount(2);

    $set1 = $newLine1->workoutTemplateSets->firstWhere('order', 0);
    expect($set1->reps)->toBe(8)
        ->and($set1->weight)->toBe('60.00')
        ->and($set1->is_warmup)->toBeFalse();

    $set2 = $newLine1->workoutTemplateSets->firstWhere('order', 1);
    expect($set2->reps)->toBe(6)
        ->and($set2->weight)->toBe('70.00')
        ->and($set2->is_warmup)->toBeTrue();

    // Verify second line
    $newLine2 = $updatedTemplate->workoutTemplateLines->firstWhere('exercise_id', $exercise1->id);
    expect($newLine2)->not->toBeNull()
        ->and($newLine2->order)->toBe(1)
        ->and($newLine2->workoutTemplateSets)->toHaveCount(1);

    $set3 = $newLine2->workoutTemplateSets->firstWhere('order', 0);
    expect($set3->reps)->toBe(12)
        ->and($set3->weight)->toBeNull()
        ->and($set3->is_warmup)->toBeFalse();
});

it('removes all lines and sets if exercises array is empty', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $exercise1 = Exercise::factory()->create();

    $line1 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise1->id,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line1->id,
    ]);

    $action = app(UpdateWorkoutTemplateAction::class);

    $updatedTemplate = $action->execute($template, [
        'name' => 'New Name',
        'exercises' => [],
    ]);

    expect($updatedTemplate->workoutTemplateLines)->toBeEmpty();

    $this->assertDatabaseMissing('workout_template_lines', [
        'id' => $line1->id,
    ]);
});
