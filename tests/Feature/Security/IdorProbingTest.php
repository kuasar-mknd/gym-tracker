<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('user cannot probe other users exercise ids', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $exerciseOfUser2 = Exercise::factory()->create(['user_id' => $user2->id]);

    actingAs($user1, 'sanctum')
        ->getJson('/api/v1/personal-records?exercise_id='.$exerciseOfUser2->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exercise_id');

    // Non-existent ID also fails with 422
    actingAs($user1, 'sanctum')
        ->getJson('/api/v1/personal-records?exercise_id=99999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exercise_id');
});

test('user cannot probe other users workout line ids', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $workoutOfUser2 = Workout::factory()->create(['user_id' => $user2->id]);
    $lineOfUser2 = WorkoutLine::factory()->create(['workout_id' => $workoutOfUser2->id]);

    actingAs($user1, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $lineOfUser2->id,
            'weight' => 100,
            'reps' => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workout_line_id');

    // Non-existent ID also fails with 422
    actingAs($user1, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => 99999,
            'weight' => 100,
            'reps' => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workout_line_id');
});
