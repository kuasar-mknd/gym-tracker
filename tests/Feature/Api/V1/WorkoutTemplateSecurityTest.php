<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('user cannot create workout template with another users private exercise', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $privateExercise = Exercise::factory()->create([
        'user_id' => $otherUser->id,
        'name' => 'Secret Exercise'
    ]);

    $data = [
        'name' => 'Hacker Template',
        'exercises' => [
            [
                'id' => $privateExercise->id,
                'sets' => [['reps' => 10, 'weight' => 100]],
            ],
        ],
    ];

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/v1/workout-templates', $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['exercises.0.id']);
});

test('user cannot update workout template with another users private exercise', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    // Own template
    $template = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
    ]);

    $privateExercise = Exercise::factory()->create([
        'user_id' => $otherUser->id,
        'name' => 'Secret Exercise'
    ]);

    $data = [
        'name' => 'Updated Hacker Template',
        'exercises' => [
            [
                'id' => $privateExercise->id,
                'sets' => [['reps' => 10, 'weight' => 100]],
            ],
        ],
    ];

    Sanctum::actingAs($user);
    $response = $this->putJson("/api/v1/workout-templates/{$template->id}", $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['exercises.0.id']);
});

test('user can create workout template with public exercise', function () {
    $user = User::factory()->create();
    // System exercise (user_id is null)
    $publicExercise = Exercise::factory()->create([
        'user_id' => null,
        'name' => 'Push Up'
    ]);

    $data = [
        'name' => 'Public Template',
        'exercises' => [
            [
                'id' => $publicExercise->id,
                'sets' => [['reps' => 10, 'weight' => 100]],
            ],
        ],
    ];

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/v1/workout-templates', $data);

    $response->assertCreated();
});
