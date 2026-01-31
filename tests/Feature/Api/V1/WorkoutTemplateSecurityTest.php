<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('user cannot create workout template with other users private exercise even if system exercise exists', function (): void {
    Sanctum::actingAs($this->user);

    // Create a system exercise (this makes sure the OR condition doesn't bypass the ID check)
    Exercise::factory()->create(['user_id' => null]);

    // Create a private exercise for the other user
    $privateExercise = Exercise::factory()->create(['user_id' => $this->otherUser->id]);

    $data = [
        'name' => 'Malicious Template',
        'exercises' => [
            [
                'id' => $privateExercise->id,
            ],
        ],
    ];

    $response = $this->postJson(route('api.v1.workout-templates.store'), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.id']);
});

test('user cannot update workout template with other users private exercise even if system exercise exists', function (): void {
    Sanctum::actingAs($this->user);

    // Create a system exercise
    Exercise::factory()->create(['user_id' => null]);

    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);

    // Create a private exercise for the other user
    $privateExercise = Exercise::factory()->create(['user_id' => $this->otherUser->id]);

    $data = [
        'name' => 'Updated Malicious Template',
        'exercises' => [
            [
                'id' => $privateExercise->id,
            ],
        ],
    ];

    $response = $this->putJson(route('api.v1.workout-templates.update', $template), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.id']);
});

test('user can create workout template with system exercise', function (): void {
    Sanctum::actingAs($this->user);

    // Create a system exercise (null user_id)
    $systemExercise = Exercise::factory()->create(['user_id' => null]);

    $data = [
        'name' => 'Valid System Template',
        'exercises' => [
            [
                'id' => $systemExercise->id,
            ],
        ],
    ];

    $response = $this->postJson(route('api.v1.workout-templates.store'), $data);

    $response->assertCreated();
});

test('user can create workout template with own private exercise', function (): void {
    Sanctum::actingAs($this->user);

    // Create a private exercise for the current user
    $ownExercise = Exercise::factory()->create(['user_id' => $this->user->id]);

    $data = [
        'name' => 'Valid Own Template',
        'exercises' => [
            [
                'id' => $ownExercise->id,
            ],
        ],
    ];

    $response = $this->postJson(route('api.v1.workout-templates.store'), $data);

    $response->assertCreated();
});
