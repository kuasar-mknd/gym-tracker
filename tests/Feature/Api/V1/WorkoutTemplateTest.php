<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest cannot access workout templates', function () {
    $response = $this->getJson('/api/v1/workout-templates');
    $response->assertUnauthorized();
});

test('user can list own workout templates', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $template = new WorkoutTemplate(['name' => 'My Template']);
    $template->user_id = $user->id;
    $template->save();

    $otherTemplate = new WorkoutTemplate(['name' => 'Other Template']);
    $otherTemplate->user_id = $otherUser->id;
    $otherTemplate->save();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/workout-templates');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['name' => 'My Template'])
        ->assertJsonMissing(['name' => 'Other Template']);
});

test('user can create workout template with nested exercises', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['name' => 'Bench Press']);

    $data = [
        'name' => 'Push Day',
        'description' => 'Best day',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 100, 'is_warmup' => true],
                    ['reps' => 5, 'weight' => 120, 'is_warmup' => false],
                ]
            ]
        ]
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/workout-templates', $data);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Push Day')
        ->assertJsonPath('data.lines.0.exercise.id', $exercise->id)
        ->assertJsonPath('data.lines.0.sets.0.reps', 10);

    $this->assertDatabaseHas('workout_templates', ['name' => 'Push Day']);
});

test('user can show workout template', function () {
    $user = User::factory()->create();
    $template = new WorkoutTemplate(['name' => 'Leg Day']);
    $template->user_id = $user->id;
    $template->save();

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/workout-templates/{$template->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $template->id)
        ->assertJsonPath('data.name', 'Leg Day');
});

test('user can update workout template', function () {
    $user = User::factory()->create();
    $template = new WorkoutTemplate(['name' => 'Leg Day']);
    $template->user_id = $user->id;
    $template->save();
    $exercise = Exercise::factory()->create();

    $data = [
        'name' => 'Updated Leg Day',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 12, 'weight' => 50]
                ]
            ]
        ]
    ];

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/workout-templates/{$template->id}", $data);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Leg Day')
        ->assertJsonPath('data.lines.0.exercise.id', $exercise->id);

    $this->assertDatabaseHas('workout_templates', ['id' => $template->id, 'name' => 'Updated Leg Day']);
});

test('user can delete workout template', function () {
    $user = User::factory()->create();
    $template = new WorkoutTemplate(['name' => 'Delete Me']);
    $template->user_id = $user->id;
    $template->save();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/workout-templates/{$template->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});
