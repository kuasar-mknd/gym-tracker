<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutTemplateAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;

it('creates a basic workout template without exercises', function (): void {
    $user = User::factory()->create();
    $action = app(CreateWorkoutTemplateAction::class);

    $data = [
        'name' => 'Push Day',
        'description' => 'A basic push day routine',
    ];

    $template = $action->execute($user, $data);

    expect($template)
        ->toBeInstanceOf(WorkoutTemplate::class)
        ->name->toBe('Push Day')
        ->description->toBe('A basic push day routine')
        ->user_id->toBe($user->id);

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'user_id' => $user->id,
        'name' => 'Push Day',
        'description' => 'A basic push day routine',
    ]);
});

it('creates a workout template with exercises and sets', function (): void {
    $user = User::factory()->create();
    $action = app(CreateWorkoutTemplateAction::class);

    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    $data = [
        'name' => 'Full Body',
        'exercises' => [
            [
                'id' => $exercise1->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 50.0, 'is_warmup' => true],
                    ['reps' => 8, 'weight' => 60.0, 'is_warmup' => false],
                ],
            ],
            [
                'id' => $exercise2->id,
                'sets' => [
                    ['reps' => 12, 'weight' => 20.0, 'is_warmup' => false],
                ],
            ],
        ],
    ];

    $template = $action->execute($user, $data);

    expect($template)->toBeInstanceOf(WorkoutTemplate::class);

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'name' => 'Full Body',
        'user_id' => $user->id,
    ]);

    $this->assertDatabaseCount('workout_template_lines', 2);
    $this->assertDatabaseHas('workout_template_lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise1->id,
        'order' => 0,
    ]);
    $this->assertDatabaseHas('workout_template_lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise2->id,
        'order' => 1,
    ]);

    $this->assertDatabaseCount('workout_template_sets', 3);

    // Check sets for exercise 1
    $line1 = $template->workoutTemplateLines()->where('exercise_id', $exercise1->id)->first();
    $this->assertDatabaseHas('workout_template_sets', [
        'workout_template_line_id' => $line1->id,
        'reps' => 10,
        'weight' => 50.0,
        'is_warmup' => 1,
        'order' => 0,
    ]);
    $this->assertDatabaseHas('workout_template_sets', [
        'workout_template_line_id' => $line1->id,
        'reps' => 8,
        'weight' => 60.0,
        'is_warmup' => 0,
        'order' => 1,
    ]);

    // Check sets for exercise 2
    $line2 = $template->workoutTemplateLines()->where('exercise_id', $exercise2->id)->first();
    $this->assertDatabaseHas('workout_template_sets', [
        'workout_template_line_id' => $line2->id,
        'reps' => 12,
        'weight' => 20.0,
        'is_warmup' => 0,
        'order' => 0,
    ]);
});

it('creates a workout template with exercises but without sets', function (): void {
    $user = User::factory()->create();
    $action = app(CreateWorkoutTemplateAction::class);

    $exercise1 = Exercise::factory()->create();

    $data = [
        'name' => 'Cardio Day',
        'exercises' => [
            [
                'id' => $exercise1->id,
            ],
        ],
    ];

    $template = $action->execute($user, $data);

    $this->assertDatabaseHas('workout_template_lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise1->id,
        'order' => 0,
    ]);

    $this->assertDatabaseCount('workout_template_sets', 0);
});

it('rolls back transaction on error', function (): void {
    $user = User::factory()->create();
    $action = app(CreateWorkoutTemplateAction::class);

    $initialTemplateCount = WorkoutTemplate::count();

    $data = [
        'name' => 'Failed Template',
        'exercises' => [
            [
                'id' => 99999, // Invalid exercise ID to trigger foreign key constraint failure
                'sets' => [
                    ['reps' => 10, 'weight' => 50.0],
                ],
            ],
        ],
    ];

    try {
        $action->execute($user, $data);
    } catch (\Exception) {
        // Expected exception
    }

    $this->assertDatabaseCount('workout_templates', $initialTemplateCount);
    $this->assertDatabaseMissing('workout_templates', [
        'name' => 'Failed Template',
    ]);
});
