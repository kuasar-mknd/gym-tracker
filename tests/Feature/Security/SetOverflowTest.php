<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

test('set creation with massive weight and reps causes database overflow or application error', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user);

    // Max value for decimal(10,2) is 99999999.99
    // Volume = weight * reps
    // If we send 999999 and 999999, volume is way larger than decimal(10,2)

    $response = post(route('sets.store', $workoutLine), [
        'weight' => 999999,
        'reps' => 999999,
        'duration_seconds' => 0,
        'distance_km' => 0,
        'is_warmup' => false,
    ]);

    // Currently, this should probably fail with 500 or succeed and store bad data
    // We want to see if it fails nicely (422) or crashes (500)
    // If it returns 302, it might be successful redirect.
    // Let's assert that it's NOT 500 first.

    // If the application doesn't validate, it might try to insert into DB.
    // MySQL/Postgres will throw error for overflow if strict mode is on.
    // Laravel usually returns 500 for DB exceptions.

    // Expect validation error
    $response->assertSessionHasErrors(['weight', 'reps']);
    $this->assertDatabaseMissing('sets', [
        'workout_line_id' => $workoutLine->id,
        'weight' => 999999,
    ]);
});

test('set update with massive weight and reps is rejected', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = \App\Models\Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    actingAs($user);

    $response = \Pest\Laravel\patch(route('sets.update', $set), [
        'weight' => 999999,
        'reps' => 999999,
    ]);

    $response->assertSessionHasErrors(['weight', 'reps']);

    // Refresh to check DB
    $set->refresh();
    expect($set->weight)->toBe(100.0);
});
