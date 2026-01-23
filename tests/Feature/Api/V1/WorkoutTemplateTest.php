<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('unauthenticated user cannot access workout templates', function (): void {
    $this->getJson(route('api.v1.workout-templates.index'))->assertUnauthorized();
    $this->postJson(route('api.v1.workout-templates.store'), [])->assertUnauthorized();
    $template = WorkoutTemplate::factory()->create();
    $this->getJson(route('api.v1.workout-templates.show', $template))->assertUnauthorized();
    $this->putJson(route('api.v1.workout-templates.update', $template), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.workout-templates.destroy', $template))->assertUnauthorized();
});

test('user can list own workout templates', function (): void {
    Sanctum::actingAs($this->user);
    WorkoutTemplate::factory()->count(3)->create(['user_id' => $this->user->id]);

    // Create other user's template
    WorkoutTemplate::factory()->create();

    $response = $this->getJson(route('api.v1.workout-templates.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can create workout template', function (): void {
    Sanctum::actingAs($this->user);
    $exercise = Exercise::factory()->create();

    $data = [
        'name' => 'My New Template',
        'description' => 'A description',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 100, 'is_warmup' => false],
                ],
            ],
        ],
    ];

    $response = $this->postJson(route('api.v1.workout-templates.store'), $data);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'My New Template')
        ->assertJsonPath('data.lines.0.exercise.id', $exercise->id);

    $this->assertDatabaseHas('workout_templates', [
        'name' => 'My New Template',
        'user_id' => $this->user->id,
    ]);
});

test('create template requires name', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson(route('api.v1.workout-templates.store'), [
        'description' => 'No name provided',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('create template requires valid exercise id', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson(route('api.v1.workout-templates.store'), [
        'name' => 'Invalid Exercise',
        'exercises' => [
            ['id' => 999999],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.id']);
});

test('create template validates set data types', function (): void {
    Sanctum::actingAs($this->user);
    $exercise = Exercise::factory()->create();

    $response = $this->postJson(route('api.v1.workout-templates.store'), [
        'name' => 'Invalid Sets',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 'not-a-number', 'weight' => 'heavy', 'is_warmup' => 'maybe'],
                ],
            ],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'exercises.0.sets.0.reps',
            'exercises.0.sets.0.weight',
            'exercises.0.sets.0.is_warmup',
        ]);
});

test('user can show own workout template', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);

    $this->getJson(route('api.v1.workout-templates.show', $template))
        ->assertOk()
        ->assertJsonPath('data.id', $template->id);
});

test('user cannot show other users workout template', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    $this->getJson(route('api.v1.workout-templates.show', $template))
        ->assertForbidden();
});

test('user can update own workout template', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();

    $data = [
        'name' => 'Updated Name',
        'exercises' => [
            ['id' => $exercise->id],
        ],
    ];

    $this->putJson(route('api.v1.workout-templates.update', $template), $data)
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Name');

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'name' => 'Updated Name',
    ]);
});

test('user cannot update other users workout template', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    $this->putJson(route('api.v1.workout-templates.update', $template), ['name' => 'Hacked'])
        ->assertForbidden();
});

test('user can delete own workout template', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);

    $this->deleteJson(route('api.v1.workout-templates.destroy', $template))
        ->assertNoContent();

    $this->assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});

test('user cannot delete other users workout template', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    $this->deleteJson(route('api.v1.workout-templates.destroy', $template))
        ->assertForbidden();

    $this->assertDatabaseHas('workout_templates', ['id' => $template->id]);
});
