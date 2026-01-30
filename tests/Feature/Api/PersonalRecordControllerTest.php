<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('index: unauthenticated user cannot list personal records', function () {
    $response = $this->getJson(route('api.v1.personal-records.index'));
    $response->assertUnauthorized();
});

test('index: authenticated user can list own personal records', function () {
    $exercise = Exercise::factory()->create();
    PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
        'exercise_id' => $exercise->id,
        'value' => 100,
    ]);

    // Create PR for other user (should not be seen)
    PersonalRecord::factory()->create([
        'user_id' => $this->otherUser->id,
        'exercise_id' => $exercise->id,
        'value' => 200,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.personal-records.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.value', '100.00');
});

test('index: can filter personal records by exercise_id', function () {
    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
        'exercise_id' => $exercise1->id,
    ]);

    PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
        'exercise_id' => $exercise2->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.personal-records.index', ['exercise_id' => $exercise1->id]));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.exercise_id', $exercise1->id);
});

test('store: unauthenticated user cannot create personal record', function () {
    $response = $this->postJson(route('api.v1.personal-records.store'), []);
    $response->assertUnauthorized();
});

test('store: authenticated user can create personal record', function () {
    $exercise = Exercise::factory()->create();
    $data = [
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 100.5,
        'achieved_at' => now()->toDateTimeString(),
    ];

    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.personal-records.store'), $data);

    $response->assertCreated()
        ->assertJsonPath('data.value', '100.50');

    $this->assertDatabaseHas('personal_records', [
        'user_id' => $this->user->id,
        'exercise_id' => $exercise->id,
        'value' => 100.5,
    ]);
});

test('store: validates required fields', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.personal-records.store'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id', 'type', 'value', 'achieved_at']);
});

test('store: validates exercise existence', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => 99999,
            'type' => 'max_weight',
            'value' => 100,
            'achieved_at' => now()->toDateTimeString(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id']);
});

test('store: validates workout ownership', function () {
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $this->otherUser->id]);

    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 100,
            'achieved_at' => now()->toDateTimeString(),
            'workout_id' => $workout->id,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id']);
});

test('show: unauthenticated user cannot view personal record', function () {
    $pr = PersonalRecord::factory()->create();
    $response = $this->getJson(route('api.v1.personal-records.show', $pr));
    $response->assertUnauthorized();
});

test('show: authenticated user can view own personal record', function () {
    $exercise = Exercise::factory()->create();
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
        'exercise_id' => $exercise->id,
        'value' => 100,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.personal-records.show', $pr));

    $response->assertOk()
        ->assertJsonPath('data.id', $pr->id)
        ->assertJsonPath('data.value', '100.00');
});

test('show: authenticated user cannot view other users personal record', function () {
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.personal-records.show', $pr));

    $response->assertForbidden();
});

test('update: unauthenticated user cannot update personal record', function () {
    $pr = PersonalRecord::factory()->create();
    $response = $this->putJson(route('api.v1.personal-records.update', $pr), []);
    $response->assertUnauthorized();
});

test('update: authenticated user can update own personal record', function () {
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
        'value' => 100,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 150.5,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.value', '150.50');

    $this->assertDatabaseHas('personal_records', [
        'id' => $pr->id,
        'value' => 150.5,
    ]);
});

test('update: authenticated user cannot update other users personal record', function () {
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 150,
        ]);

    $response->assertForbidden();
});

test('update: validates fields', function () {
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'exercise_id' => 99999,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id']);
});

test('destroy: unauthenticated user cannot delete personal record', function () {
    $pr = PersonalRecord::factory()->create();
    $response = $this->deleteJson(route('api.v1.personal-records.destroy', $pr));
    $response->assertUnauthorized();
});

test('destroy: authenticated user can delete own personal record', function () {
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson(route('api.v1.personal-records.destroy', $pr));

    $response->assertNoContent();
    $this->assertDatabaseMissing('personal_records', ['id' => $pr->id]);
});

test('destroy: authenticated user cannot delete other users personal record', function () {
    $pr = PersonalRecord::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson(route('api.v1.personal-records.destroy', $pr));

    $response->assertForbidden();
    $this->assertDatabaseHas('personal_records', ['id' => $pr->id]);
});
