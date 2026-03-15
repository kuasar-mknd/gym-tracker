<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;

it('creates a workout from a template with lines and sets', function (): void {
    // Arrange
    $user = User::factory()->create();

    $template = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Test Template',
    ]);

    $line1 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'order' => 1,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line1->id,
        'reps' => 10,
        'weight' => 50,
        'is_warmup' => true,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line1->id,
        'reps' => 8,
        'weight' => 60,
        'is_warmup' => false,
    ]);

    $line2 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'order' => 2,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line2->id,
        'reps' => 12,
        'weight' => 40,
        'is_warmup' => false,
    ]);

    // Act
    $action = app(CreateWorkoutFromTemplateAction::class);
    $workout = $action->execute($user, $template);

    // Assert
    expect($workout)->toBeInstanceOf(\App\Models\Workout::class)
        ->and($workout->user_id)->toBe($user->id)
        ->and($workout->name)->toBe('My Test Template')
        ->and($workout->started_at)->not->toBeNull();

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'user_id' => $user->id,
        'name' => 'My Test Template',
    ]);

    // Assert lines were created correctly
    $this->assertDatabaseCount('workout_lines', 2);

    // Assert sets were created correctly
    $this->assertDatabaseCount('sets', 3);

    // Reload workout with lines and sets to assert details
    $workout->load('workoutLines.sets');

    expect($workout->workoutLines)->toHaveCount(2);

    $createdLine1 = $workout->workoutLines->firstWhere('order', 1);
    expect($createdLine1)->not->toBeNull()
        ->and($createdLine1->exercise_id)->toBe($line1->exercise_id)
        ->and($createdLine1->sets)->toHaveCount(2);

    $createdLine1Set1 = $createdLine1->sets->firstWhere('is_warmup', true);
    expect($createdLine1Set1)->not->toBeNull()
        ->and($createdLine1Set1->reps)->toBe(10)
        ->and($createdLine1Set1->weight)->toBe(50.0);

    $createdLine1Set2 = $createdLine1->sets->firstWhere('is_warmup', false);
    expect($createdLine1Set2)->not->toBeNull()
        ->and($createdLine1Set2->reps)->toBe(8)
        ->and($createdLine1Set2->weight)->toBe(60.0);

    $createdLine2 = $workout->workoutLines->firstWhere('order', 2);
    expect($createdLine2)->not->toBeNull()
        ->and($createdLine2->exercise_id)->toBe($line2->exercise_id)
        ->and($createdLine2->sets)->toHaveCount(1);

    $createdLine2Set1 = $createdLine2->sets->first();
    expect($createdLine2Set1)->not->toBeNull()
        ->and($createdLine2Set1->reps)->toBe(12)
        ->and($createdLine2Set1->weight)->toBe(40.0)
        ->and($createdLine2Set1->is_warmup)->toBeFalse();
});
