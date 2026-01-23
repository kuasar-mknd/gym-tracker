<?php

declare(strict_types=1);

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

describe('Guest', function (): void {
    test('cannot list goals', function (): void {
        getJson(route('api.v1.goals.index'))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their goals', function (): void {
            Goal::factory()->count(3)->create(['user_id' => $this->user->id]);

            $response = getJson(route('api.v1.goals.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'title', 'type', 'target_value', 'current_value', 'deadline'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('user can sort goals', function (): void {
            $goal1 = Goal::factory()->create(['user_id' => $this->user->id, 'created_at' => now()->subDays(2)]);
            $goal2 = Goal::factory()->create(['user_id' => $this->user->id, 'created_at' => now()]);

            // Default sort is -created_at
            $response = getJson(route('api.v1.goals.index'));
            $response->assertJsonPath('data.0.id', $goal2->id);

            // Sort by created_at asc
            $response = getJson(route('api.v1.goals.index', ['sort' => 'created_at']));
            $response->assertJsonPath('data.0.id', $goal1->id);
        });

        test('user can include exercise', function (): void {
            $exercise = Exercise::factory()->create();
            Goal::factory()->create([
                'user_id' => $this->user->id,
                'exercise_id' => $exercise->id,
                'type' => 'weight',
            ]);

            $response = getJson(route('api.v1.goals.index', ['include' => 'exercise']));

            $response->assertOk()
                ->assertJsonPath('data.0.exercise.id', $exercise->id);
        });

        test('user cannot see others goals', function (): void {
            $otherUser = User::factory()->create();
            Goal::factory()->create(['user_id' => $otherUser->id]);

            $response = getJson(route('api.v1.goals.index'));

            $response->assertOk()
                ->assertJsonCount(0, 'data');
        });
    });

    describe('Store', function (): void {
        test('user can create a goal', function (): void {
            $exercise = Exercise::factory()->create();

            $data = [
                'title' => 'New Goal',
                'type' => 'weight',
                'target_value' => 100,
                'start_value' => 50,
                'exercise_id' => $exercise->id,
                'deadline' => now()->addMonth()->format('Y-m-d'),
            ];

            $response = postJson(route('api.v1.goals.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.title', 'New Goal')
                ->assertJsonPath('data.type', 'weight');

            assertDatabaseHas('goals', [
                'user_id' => $this->user->id,
                'title' => 'New Goal',
            ]);
        });

        test('validation: required fields', function (): void {
            postJson(route('api.v1.goals.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['title', 'type', 'target_value']);
        });

        test('validation: type must be valid', function (): void {
            postJson(route('api.v1.goals.store'), ['type' => 'invalid'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['type']);
        });

        test('validation: exercise_id required for weight type', function (): void {
            postJson(route('api.v1.goals.store'), [
                'title' => 'Bench Press',
                'type' => 'weight',
                'target_value' => 100,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['exercise_id']);
        });

        test('validation: measurement_type required for measurement type', function (): void {
            postJson(route('api.v1.goals.store'), [
                'title' => 'Waist',
                'type' => 'measurement',
                'target_value' => 80,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['measurement_type']);
        });

        test('validation: deadline must be in future', function (): void {
            postJson(route('api.v1.goals.store'), [
                'title' => 'Future Goal',
                'type' => 'frequency',
                'target_value' => 5,
                'deadline' => now()->subDay()->format('Y-m-d'),
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['deadline']);
        });
    });

    describe('Show', function (): void {
        test('user can view their goal', function (): void {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);

            getJson(route('api.v1.goals.show', $goal))
                ->assertOk()
                ->assertJsonPath('data.id', $goal->id);
        });

        test('user cannot view others goal', function (): void {
            $otherUser = User::factory()->create();
            $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.goals.show', $goal))
                ->assertForbidden();
        });

        test('shows exercise if included', function (): void {
            $exercise = Exercise::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $this->user->id,
                'exercise_id' => $exercise->id,
            ]);

            getJson(route('api.v1.goals.show', $goal))
                ->assertOk()
                ->assertJsonPath('data.exercise.id', $exercise->id);
        });
    });

    describe('Update', function (): void {
        test('user can update their goal', function (): void {
            $goal = Goal::factory()->create(['user_id' => $this->user->id, 'title' => 'Old Title']);

            putJson(route('api.v1.goals.update', $goal), ['title' => 'New Title'])
                ->assertOk()
                ->assertJsonPath('data.title', 'New Title');

            assertDatabaseHas('goals', ['id' => $goal->id, 'title' => 'New Title']);
        });

        test('user cannot update others goal', function (): void {
            $otherUser = User::factory()->create();
            $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.goals.update', $goal), ['title' => 'Hacked'])
                ->assertForbidden();
        });

        test('validation: partial update', function (): void {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);

            putJson(route('api.v1.goals.update', $goal), ['target_value' => 200])
                ->assertOk()
                ->assertJsonPath('data.target_value', 200);
        });

        test('validation: deadline can be any date on update', function (): void {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);

            putJson(route('api.v1.goals.update', $goal), ['deadline' => now()->subDay()->format('Y-m-d')])
                ->assertOk();
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their goal', function (): void {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);

            deleteJson(route('api.v1.goals.destroy', $goal))
                ->assertNoContent();

            assertDatabaseMissing('goals', ['id' => $goal->id]);
        });

        test('user cannot delete others goal', function (): void {
            $otherUser = User::factory()->create();
            $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.goals.destroy', $goal))
                ->assertForbidden();

            assertDatabaseHas('goals', ['id' => $goal->id]);
        });
    });
});
