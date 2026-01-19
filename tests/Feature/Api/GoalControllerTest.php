<?php

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Authenticated User', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can list their goals', function () {
        $goals = Goal::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        // Create a goal for another user
        Goal::factory()->create();

        $response = getJson(route('api.v1.goals.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'type',
                        'target_value',
                        'progress',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    test('user cannot see other users goals in index', function () {
        $otherUser = User::factory()->create();
        Goal::factory()->create(['user_id' => $otherUser->id]);

        $response = getJson(route('api.v1.goals.index'));

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('user can create a goal', function () {
        $data = [
            'title' => 'Bench Press 100kg',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => Exercise::factory()->create()->id,
            'start_value' => 50,
        ];

        $response = postJson(route('api.v1.goals.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'title' => 'Bench Press 100kg',
                'target_value' => 100,
            ]);

        assertDatabaseHas('goals', [
            'user_id' => $this->user->id,
            'title' => 'Bench Press 100kg',
        ]);
    });

    test('user cannot create goal with invalid data', function () {
        $response = postJson(route('api.v1.goals.store'), [
            'title' => '', // Required
            'type' => 'invalid', // Invalid enum
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'type', 'target_value']);
    });

    test('user needs exercise_id for weight goal', function () {
        $response = postJson(route('api.v1.goals.store'), [
            'title' => 'Test Goal',
            'type' => 'weight',
            'target_value' => 100,
            // exercise_id missing
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['exercise_id']);
    });

    test('user needs measurement_type for measurement goal', function () {
        $response = postJson(route('api.v1.goals.store'), [
            'title' => 'Test Goal',
            'type' => 'measurement',
            'target_value' => 80,
            // measurement_type missing
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['measurement_type']);
    });

    test('deadline must be in the future', function () {
        $response = postJson(route('api.v1.goals.store'), [
            'title' => 'Test Goal',
            'type' => 'frequency',
            'target_value' => 3,
            'deadline' => now()->subDay()->toDateString(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['deadline']);
    });

    test('user can view their own goal', function () {
        $goal = Goal::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = getJson(route('api.v1.goals.show', $goal));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $goal->id,
                'title' => $goal->title,
            ]);
    });

    test('user cannot view others goal', function () {
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = getJson(route('api.v1.goals.show', $goal));

        $response->assertForbidden();
    });

    test('user can update their goal', function () {
        $goal = Goal::factory()->create([
            'user_id' => $this->user->id,
            'target_value' => 100,
        ]);

        $response = putJson(route('api.v1.goals.update', $goal), [
            'target_value' => 120,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'target_value' => 120,
            ]);

        assertDatabaseHas('goals', [
            'id' => $goal->id,
            'target_value' => 120,
        ]);
    });

    test('user cannot update others goal', function () {
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $otherUser->id,
            'target_value' => 100,
        ]);

        $response = putJson(route('api.v1.goals.update', $goal), [
            'target_value' => 120,
        ]);

        $response->assertForbidden();
    });

    test('user can delete their goal', function () {
        $goal = Goal::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = deleteJson(route('api.v1.goals.destroy', $goal));

        $response->assertNoContent();

        assertDatabaseMissing('goals', ['id' => $goal->id]);
    });

    test('user cannot delete others goal', function () {
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = deleteJson(route('api.v1.goals.destroy', $goal));

        $response->assertForbidden();

        assertDatabaseHas('goals', ['id' => $goal->id]);
    });
});

describe('Unauthenticated User', function () {
    test('guest cannot list goals', function () {
        $response = getJson(route('api.v1.goals.index'));
        $response->assertUnauthorized();
    });

    test('guest cannot create goal', function () {
        $response = postJson(route('api.v1.goals.store'), []);
        $response->assertUnauthorized();
    });

    test('guest cannot view goal', function () {
        $goal = Goal::factory()->create();
        $response = getJson(route('api.v1.goals.show', $goal));
        $response->assertUnauthorized();
    });

    test('guest cannot update goal', function () {
        $goal = Goal::factory()->create();
        $response = putJson(route('api.v1.goals.update', $goal), []);
        $response->assertUnauthorized();
    });

    test('guest cannot delete goal', function () {
        $goal = Goal::factory()->create();
        $response = deleteJson(route('api.v1.goals.destroy', $goal));
        $response->assertUnauthorized();
    });
});
