<?php

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

// Happy Path Tests

test('user can list personal records', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    PersonalRecord::factory()->count(3)->create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can filter personal records by exercise', function () {
    $user = User::factory()->create();
    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    PersonalRecord::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise1->id]);
    PersonalRecord::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise2->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.index', ['exercise_id' => $exercise1->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.exercise.id', $exercise1->id);
});

test('user can create a personal record', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $data = [
        'exercise_id' => $exercise->id,
        'type' => '1RM',
        'value' => 100.5,
        'workout_id' => $workout->id,
        'achieved_at' => now()->toDateString(),
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.value', 100.5)
        ->assertJsonPath('data.type', '1RM');

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'value' => 100.5,
    ]);
});

test('user can show a personal record', function () {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.show', $pr))
        ->assertOk()
        ->assertJsonPath('data.id', $pr->id);
});

test('user can update a personal record', function () {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id, 'value' => 100]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 110.5,
        ])
        ->assertOk()
        ->assertJsonPath('data.value', 110.5);

    assertDatabaseHas('personal_records', [
        'id' => $pr->id,
        'value' => 110.5,
    ]);
});

test('user can delete a personal record', function () {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.personal-records.destroy', $pr))
        ->assertNoContent();

    assertDatabaseMissing('personal_records', ['id' => $pr->id]);
});

// Validation Tests

test('store requires mandatory fields', function () {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id', 'type', 'value', 'achieved_at']);
});

test('store validates exercise existence', function () {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => 99999,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id']);
});

test('store validates numeric value', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 'not-a-number',
            'achieved_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});

test('store validates date format', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => 'not-a-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['achieved_at']);
});

test('update validates input types', function () {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 'not-a-number',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});

// Authorization Tests

test('guest cannot access endpoints', function () {
    $pr = PersonalRecord::factory()->create();

    $this->getJson(route('api.v1.personal-records.index'))->assertUnauthorized();
    $this->postJson(route('api.v1.personal-records.store'), [])->assertUnauthorized();
    $this->getJson(route('api.v1.personal-records.show', $pr))->assertUnauthorized();
    $this->putJson(route('api.v1.personal-records.update', $pr), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.personal-records.destroy', $pr))->assertUnauthorized();
});

test('user cannot view other user personal record', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.show', $pr))
        ->assertForbidden();
});

test('user cannot update other user personal record', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 200,
        ])
        ->assertForbidden();
});

test('user cannot delete other user personal record', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.personal-records.destroy', $pr))
        ->assertForbidden();
});
