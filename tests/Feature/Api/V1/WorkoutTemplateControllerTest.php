<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use Laravel\Sanctum\Sanctum;

test('index returns list of user workout templates', function (): void {
    $user = User::factory()->create();
    $templates = WorkoutTemplate::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workout-templates.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'description'],
            ],
            'links',
            'meta',
        ]);
});

test('index only shows authenticated user workout templates', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    WorkoutTemplate::factory()->count(2)->create(['user_id' => $user->id]);
    WorkoutTemplate::factory()->count(3)->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workout-templates.index'));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('unauthenticated user cannot list workout templates', function (): void {
    $response = $this->getJson(route('api.v1.workout-templates.index'));

    $response->assertUnauthorized();
});

test('store creates new workout template', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $exercise = Exercise::factory()->create();

    $data = [
        'name' => 'Push Day',
        'description' => 'Heavy push day',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 50, 'is_warmup' => false],
                    ['reps' => 8, 'weight' => 60, 'is_warmup' => false],
                ],
            ],
        ],
    ];

    $response = $this->postJson(route('api.v1.workout-templates.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment(['name' => 'Push Day']);

    $this->assertDatabaseHas('workout_templates', [
        'user_id' => $user->id,
        'name' => 'Push Day',
        'description' => 'Heavy push day',
    ]);

    $this->assertDatabaseHas('workout_template_lines', [
        'exercise_id' => $exercise->id,
    ]);

    $this->assertDatabaseHas('workout_template_sets', [
        'reps' => 10,
        'weight' => 50,
    ]);
});

test('store requires name', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.v1.workout-templates.store'), [
        'description' => 'Test',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('show returns workout template details', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workout-templates.show', $template));

    $response->assertOk()
        ->assertJsonFragment(['id' => $template->id, 'name' => $template->name]);
});

test('show returns 403 for other user workout template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workout-templates.show', $template));

    $response->assertForbidden();
});

test('show returns 404 for non-existent workout template', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workout-templates.show', 99999));

    $response->assertNotFound();
});

test('update modifies workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);
    $exercise = Exercise::factory()->create();

    $data = [
        'name' => 'Updated Template',
        'description' => 'Updated desc',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 5, 'weight' => 100, 'is_warmup' => false],
                ],
            ],
        ],
    ];

    $response = $this->putJson(route('api.v1.workout-templates.update', $template), $data);

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Updated Template']);

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'name' => 'Updated Template',
        'description' => 'Updated desc',
    ]);
});

test('update returns 403 for other user workout template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.v1.workout-templates.update', $template), ['name' => 'Updated Template Name']);

    $response->assertForbidden();
});

test('update validates input', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.v1.workout-templates.update', $template), [
        'name' => '',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('destroy deletes workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.workout-templates.destroy', $template));

    $response->assertNoContent();

    $this->assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});

test('destroy returns 403 for other user workout template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.workout-templates.destroy', $template));

    $response->assertForbidden();
});
