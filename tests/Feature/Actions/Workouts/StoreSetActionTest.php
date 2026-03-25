<?php

declare(strict_types=1);

use App\Actions\Workouts\StoreSetAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

it('creates a set when validation passes and authorized', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'reps' => 10,
        'weight' => 100,
    ];

    $action = app(StoreSetAction::class);

    $set = $action->execute($user, $data);

    expect($set)->toBeInstanceOf(Set::class)
        ->and($set->workout_line_id)->toBe($workoutLine->id)
        ->and($set->reps)->toBe(10)
        ->and($set->weight)->toBe(100.0);
});

it('logs and rethrows exception when set creation fails', function (): void {
    $user = User::factory()->create();
    $data = ['workout_line_id' => 99999];

    // Spy on Log facade
    Log::spy();

    $action = app(StoreSetAction::class);

    // Expect an exception because the WorkoutLine doesn't exist (findOrFail will throw ModelNotFoundException)
    try {
        $action->execute($user, $data);
        $this->fail('Expected exception was not thrown');
    } catch (\Exception $e) {
        expect($e)->toBeInstanceOf(ModelNotFoundException::class);
    }

    // Assert Log::error was called
    Log::shouldHaveReceived('error')->once()->with(
        'Failed to create set in API:',
        \Mockery::on(fn(array $context): bool => isset($context['error'])
            && isset($context['trace'])
            && $context['user_id'] === $user->id
            && $context['data'] === $data)
    );
});
