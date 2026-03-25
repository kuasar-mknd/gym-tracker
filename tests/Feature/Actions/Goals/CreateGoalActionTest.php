<?php

declare(strict_types=1);

use App\Actions\Goals\CreateGoalAction;
use App\Enums\GoalType;
use App\Models\Goal;
use App\Models\User;

it('creates a goal and assigns start_value to 0 if not provided', function (): void {
    // Arrange
    $user = User::factory()->create();
    $data = [
        'title' => 'Lose weight',
        'type' => GoalType::Measurement->value,
        'target_value' => 70,
        'measurement_type' => 'weight',
        'deadline' => now()->addMonths(3)->format('Y-m-d'),
    ];

    $action = app(CreateGoalAction::class);

    // Act
    $goal = $action->execute($user, $data);

    // Assert
    expect($goal)->toBeInstanceOf(Goal::class)
        ->and($goal->user_id)->toBe($user->id)
        ->and($goal->title)->toBe('Lose weight')
        ->and($goal->start_value)->toBe(0.0)
        ->and($goal->target_value)->toBe(70.0);

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'user_id' => $user->id,
        'title' => 'Lose weight',
        'start_value' => 0,
        'target_value' => 70,
    ]);
});

it('creates a goal using the provided start_value', function (): void {
    // Arrange
    $user = User::factory()->create();
    $data = [
        'title' => 'Bench Press 100kg',
        'type' => GoalType::Weight->value,
        'target_value' => 100,
        'start_value' => 80,
    ];

    $action = app(CreateGoalAction::class);

    // Act
    $goal = $action->execute($user, $data);

    // Assert
    expect($goal)->toBeInstanceOf(Goal::class)
        ->and($goal->user_id)->toBe($user->id)
        ->and($goal->title)->toBe('Bench Press 100kg')
        ->and($goal->start_value)->toBe(80.0)
        ->and($goal->target_value)->toBe(100.0);

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'user_id' => $user->id,
        'title' => 'Bench Press 100kg',
        'start_value' => 80,
        'target_value' => 100,
    ]);
});
