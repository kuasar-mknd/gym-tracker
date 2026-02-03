<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

test('creating set creates max weight pr', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $data = [
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 5,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertCreated();

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 100,
    ]);
});

test('creating set creates max volume pr', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Volume = 100 * 10 = 1000
    $data = [
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertCreated();

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_volume_set',
        'value' => 1000,
    ]);
});

test('updating set updates pr', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 5,
    ]);
    $setId = $response->json('data.id');

    // Update to higher weight
    putJson(route('api.v1.sets.update', $setId), [
        'weight' => 120,
        'reps' => 5,
    ])->assertOk();

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 120,
    ]);
});

test('validation prevents invalid set creation and no pr created', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Invalid weight
    $data = [
        'workout_line_id' => $line->id,
        'weight' => 'invalid',
        'reps' => 10,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertUnprocessable();

    assertDatabaseMissing('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
    ]);
});

test('authorization prevents pr update on other users line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $data = [
        'workout_line_id' => $line->id,
        'weight' => 200,
        'reps' => 10,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertForbidden();

    assertDatabaseMissing('personal_records', [
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 200,
    ]);
});
