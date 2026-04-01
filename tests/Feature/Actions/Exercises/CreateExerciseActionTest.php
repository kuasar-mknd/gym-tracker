<?php

declare(strict_types=1);

use App\Actions\Exercises\CreateExerciseAction;
use App\Enums\ExerciseCategory;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('creates an exercise and invalidates cache', function (): void {
    // Arrange
    $user = User::factory()->create();
    $data = [
        'name' => 'Bench Press',
        'type' => 'strength',
        'category' => ExerciseCategory::Pectoraux->value,
        'default_rest_time' => 90,
    ];

    Cache::shouldReceive('get')
        ->with('exercises_global_version', '1')
        ->andReturn('1');

    Cache::shouldReceive('forget')
        ->with("exercises_list_{$user->id}_v1")
        ->atLeast()->once();

    Cache::shouldReceive('forget')
        ->with("exercises_list_{$user->id}")
        ->atLeast()->once();

    // Act
    $action = app(CreateExerciseAction::class);
    $exercise = $action->execute($user, $data);

    // Assert
    expect($exercise)->toBeInstanceOf(Exercise::class)
        ->and($exercise->user_id)->toBe($user->id)
        ->and($exercise->name)->toBe('Bench Press')
        ->and($exercise->type)->toBe('strength')
        ->and($exercise->category->value)->toBe(ExerciseCategory::Pectoraux->value)
        ->and($exercise->default_rest_time)->toBe(90);

    $this->assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'user_id' => $user->id,
        'name' => 'Bench Press',
        'type' => 'strength',
        'category' => ExerciseCategory::Pectoraux->value,
        'default_rest_time' => 90,
    ]);
});
