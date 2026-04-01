<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutAction;
use App\Jobs\RecalculateUserStats;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('creates a workout and dispatches stats recalculation job', function (): void {
    // Arrange
    Queue::fake();

    $user = User::factory()->create();
    $workoutData = [
        'name' => 'Morning Run',
        'started_at' => now()->subHour()->toDateTimeString(),
        'ended_at' => now()->toDateTimeString(),
        'notes' => 'Felt good',
    ];

    $action = app(CreateWorkoutAction::class);

    // Act
    $workout = $action->execute($user, $workoutData);

    // Assert
    expect($workout->user_id)->toBe($user->id)
        ->and($workout->name)->toBe('Morning Run')
        ->and($workout->notes)->toBe('Felt good');

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'user_id' => $user->id,
        'name' => 'Morning Run',
    ]);

    Queue::assertPushed(RecalculateUserStats::class, function (RecalculateUserStats $job) use ($user): bool {
        return $job->user->id === $user->id;
    });
});
