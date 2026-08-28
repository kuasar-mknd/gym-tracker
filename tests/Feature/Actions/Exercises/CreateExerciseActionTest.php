<?php

declare(strict_types=1);

use App\Actions\Exercises\CreateExerciseAction;
use App\Enums\ExerciseCategory;
use App\Models\Exercise;
use App\Models\User;

it('creates an exercise and invalidates cache', function (): void {
    // Arrange
    $user = User::factory()->create();
    $data = [
        'name' => 'Bench Press',
        'type' => 'strength',
        'category' => ExerciseCategory::Pectoraux->value,
        'default_rest_time' => 90,
    ];

    // La liste est mise en cache avant, pour que sa fraicheur apres soit une
    // preuve d'invalidation et non un premier remplissage.
    Exercise::getCachedForUser($user->id);

    // Act
    $action = app(CreateExerciseAction::class);
    $exercise = $action->execute($user, $data);

    // Assert
    expect(Exercise::getCachedForUser($user->id)->pluck('name')->all())->toContain('Bench Press')
        ->and($exercise)->toBeInstanceOf(Exercise::class)
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
