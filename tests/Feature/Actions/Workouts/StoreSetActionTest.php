<?php

declare(strict_types=1);

use App\Actions\Workouts\StoreSetAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

it('stores a set for a workout line successfully', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ];

    $action = app(StoreSetAction::class);
    $set = $action->execute($user, $data);

    expect($set)->toBeInstanceOf(Set::class)
        ->and($set->workout_line_id)->toBe($workoutLine->id)
        ->and($set->weight)->toBe(100.5)
        ->and($set->reps)->toBe(10)
        ->and($set->is_warmup)->toBeFalse()
        ->and($set->is_completed)->toBeTrue();

    $this->assertDatabaseHas('sets', [
        'id' => $set->id,
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ]);
});

it('throws ModelNotFoundException and logs error if workout line does not exist', function (): void {
    $user = User::factory()->create();

    $data = [
        'workout_line_id' => 99999,
        'weight' => 100.5,
        'reps' => 10,
    ];

    Log::shouldReceive('error')->once();

    $action = app(StoreSetAction::class);

    expect(fn () => $action->execute($user, $data))
        ->toThrow(ModelNotFoundException::class);
});

it('throws AuthorizationException and logs error if user is not authorized', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user1->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
    ];

    Log::shouldReceive('error')->once();

    $action = app(StoreSetAction::class);

    expect(fn () => $action->execute($user2, $data))
        ->toThrow(AuthorizationException::class);
});

it('throws AuthorizationException and logs error if workout is ended', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'ended_at' => now(),
    ]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
    ];

    Log::shouldReceive('error')->once();

    $action = app(StoreSetAction::class);

    expect(fn () => $action->execute($user, $data))
        ->toThrow(AuthorizationException::class);
});
