<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutAction;
use App\Jobs\RecalculateUserStats;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Queue;

it('creates a workout and dispatches stats recalculation job', function (): void {
    // Arrange
    Queue::fake();

    $user = User::factory()->create();
    $data = [
        'name' => 'Morning Routine',
        'started_at' => now()->subHours(1)->toDateTimeString(),
    ];

    // Act
    $action = app(CreateWorkoutAction::class);
    $workout = $action->execute($user, $data);

    // Assert
    expect($workout)->toBeInstanceOf(Workout::class)
        ->and($workout->user_id)->toBe($user->id)
        ->and($workout->name)->toBe('Morning Routine');

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'user_id' => $user->id,
        'name' => 'Morning Routine',
    ]);

    Queue::assertPushed(RecalculateUserStats::class, fn ($job): bool => $job->user->id === $user->id);
});
