<?php

declare(strict_types=1);

use App\Actions\Goals\CreateGoalAction;
use App\Enums\GoalType;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('creates a goal and sets default start_value to 0 if not provided', function (): void {
    // Arrange
    /** @var User $user */
    $user = User::factory()->create();

    $data = [
        'title' => 'Test Goal',
        'type' => GoalType::Weight->value,
        'target_value' => 100.0,
        'deadline' => now()->addMonths(6)->format('Y-m-d'),
    ];

    $goalService = app(GoalService::class);
    $action = new CreateGoalAction($goalService);

    // Act
    $goal = $action->execute($user, $data);

    // Assert
    expect($goal->start_value)->toBe(0.0)
        ->and($goal->title)->toBe('Test Goal')
        ->and($goal->type)->toBe(GoalType::Weight)
        ->and($goal->target_value)->toBe(100.0)
        ->and($goal->user_id)->toBe($user->id);

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'user_id' => $user->id,
        'start_value' => 0.0,
    ]);
});

it('creates a goal using the provided start_value', function (): void {
    // Arrange
    /** @var User $user */
    $user = User::factory()->create();

    $data = [
        'title' => 'Bench Press',
        'type' => GoalType::Weight->value,
        'target_value' => 100.0,
        'start_value' => 60.0,
        'deadline' => now()->addMonths(3)->format('Y-m-d'),
    ];

    $goalService = app(GoalService::class);
    $action = new CreateGoalAction($goalService);

    // Act
    $goal = $action->execute($user, $data);

    // Assert
    expect($goal->start_value)->toBe(60.0)
        ->and($goal->title)->toBe('Bench Press')
        ->and($goal->type)->toBe(GoalType::Weight)
        ->and($goal->target_value)->toBe(100.0)
        ->and($goal->user_id)->toBe($user->id);

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'user_id' => $user->id,
        'start_value' => 60.0,
    ]);
});
