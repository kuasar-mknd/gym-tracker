<?php

use App\Jobs\RecalculateUserStats;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

test('index returns list of user workouts', function (): void {
    $user = User::factory()->create();
    $workouts = Workout::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workouts.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'started_at', 'ended_at', 'notes'],
            ],
            'links',
            'meta',
        ]);
});

test('index only shows authenticated user workouts', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Workout::factory()->count(2)->create(['user_id' => $user->id]);
    Workout::factory()->count(3)->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workouts.index'));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('unauthenticated user cannot list workouts', function (): void {
    $response = $this->getJson(route('api.v1.workouts.index'));

    $response->assertUnauthorized();
});

test('store creates new workout and dispatches job', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'name' => 'Morning Workout',
        'started_at' => now()->toIso8601String(),
        'notes' => 'Feeling good',
    ];

    $response = $this->postJson(route('api.v1.workouts.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment(['name' => 'Morning Workout']);

    $this->assertDatabaseHas('workouts', [
        'user_id' => $user->id,
        'name' => 'Morning Workout',
    ]);

    Queue::assertPushed(RecalculateUserStats::class);
});

test('store requires name', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.v1.workouts.store'), [
        'started_at' => now()->toIso8601String(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('show returns workout details', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workouts.show', $workout));

    $response->assertOk()
        ->assertJsonFragment(['id' => $workout->id, 'name' => $workout->name]);
});

test('show returns 403 for other user workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workouts.show', $workout));

    $response->assertForbidden();
});

test('show returns 404 for non-existent workout', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.workouts.show', 99999));

    $response->assertNotFound();
});

test('update modifies workout and dispatches job', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $data = [
        'name' => 'Updated Workout',
        'notes' => 'Updated notes',
    ];

    $response = $this->putJson(route('api.v1.workouts.update', $workout), $data);

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Updated Workout']);

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'name' => 'Updated Workout',
    ]);

    Queue::assertPushed(RecalculateUserStats::class);
});

test('update returns 403 for other user workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.v1.workouts.update', $workout), ['name' => 'Hacked']);

    $response->assertForbidden();
});

test('update validates input', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    // name is sometimes|required, so if present must be string
    $response = $this->putJson(route('api.v1.workouts.update', $workout), [
        'name' => '',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('destroy deletes workout and dispatches job', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.workouts.destroy', $workout));

    $response->assertNoContent();

    $this->assertDatabaseMissing('workouts', ['id' => $workout->id]);

    Queue::assertPushed(RecalculateUserStats::class);
});

test('destroy returns 403 for other user workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.workouts.destroy', $workout));

    $response->assertForbidden();
});
