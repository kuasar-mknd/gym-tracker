<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Authenticated User', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can list their habits', function (): void {
        Habit::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        // Create a habit for another user
        Habit::factory()->create();

        $response = getJson(route('api.v1.habits.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'color',
                        'icon',
                        'goal_times_per_week',
                        'archived',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    test('user cannot see other users habits', function (): void {
        $otherUser = User::factory()->create();
        Habit::factory()->create(['user_id' => $otherUser->id]);

        $response = getJson(route('api.v1.habits.index'));

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('user can paginate habits', function (): void {
        Habit::factory()->count(20)->create([
            'user_id' => $this->user->id,
        ]);

        $response = getJson(route('api.v1.habits.index', ['per_page' => 10]));

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 20);
    });

    test('user can create a habit', function (): void {
        $data = [
            'name' => 'Drink Water',
            'goal_times_per_week' => 7,
            'description' => 'Daily hydration',
        ];

        $response = postJson(route('api.v1.habits.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'Drink Water',
                'goal_times_per_week' => 7,
                'description' => 'Daily hydration',
                // Default values
                'color' => 'bg-slate-500',
                'icon' => 'check_circle',
            ]);

        assertDatabaseHas('habits', [
            'user_id' => $this->user->id,
            'name' => 'Drink Water',
        ]);
    });

    test('user can create a habit with custom color and icon', function (): void {
        $data = [
            'name' => 'Meditation',
            'goal_times_per_week' => 5,
            'color' => 'bg-purple-500',
            'icon' => 'self_improvement',
        ];

        $response = postJson(route('api.v1.habits.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'color' => 'bg-purple-500',
                'icon' => 'self_improvement',
            ]);
    });

    test('user cannot create habit with invalid data', function (): void {
        $response = postJson(route('api.v1.habits.store'), [
            'name' => '', // Required
            'goal_times_per_week' => 8, // Max 7
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'goal_times_per_week']);
    });

    test('user can view their own habit', function (): void {
        $habit = Habit::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create some logs
        HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => now()]);

        $response = getJson(route('api.v1.habits.show', $habit));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $habit->id,
                'name' => $habit->name,
            ])
            ->assertJsonStructure(['data' => ['logs']]);
    });

    test('user cannot view others habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = getJson(route('api.v1.habits.show', $habit));

        $response->assertForbidden();
    });

    test('user can update their habit', function (): void {
        $habit = Habit::factory()->create([
            'user_id' => $this->user->id,
            'goal_times_per_week' => 5,
        ]);

        $response = putJson(route('api.v1.habits.update', $habit), [
            'goal_times_per_week' => 3,
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'goal_times_per_week' => 3,
                'name' => 'Updated Name',
            ]);

        assertDatabaseHas('habits', [
            'id' => $habit->id,
            'goal_times_per_week' => 3,
            'name' => 'Updated Name',
        ]);
    });

    test('user cannot update others habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create([
            'user_id' => $otherUser->id,
            'goal_times_per_week' => 5,
        ]);

        $response = putJson(route('api.v1.habits.update', $habit), [
            'goal_times_per_week' => 3,
        ]);

        $response->assertForbidden();

        // Ensure not updated
        assertDatabaseHas('habits', [
            'id' => $habit->id,
            'goal_times_per_week' => 5,
        ]);
    });

    test('user cannot update habit with invalid data', function (): void {
        $habit = Habit::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = putJson(route('api.v1.habits.update', $habit), [
            'goal_times_per_week' => 0, // Min 1
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['goal_times_per_week']);
    });

    test('user can delete their habit', function (): void {
        $habit = Habit::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = deleteJson(route('api.v1.habits.destroy', $habit));

        $response->assertNoContent();

        assertDatabaseMissing('habits', ['id' => $habit->id]);
    });

    test('user cannot delete others habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = deleteJson(route('api.v1.habits.destroy', $habit));

        $response->assertForbidden();

        assertDatabaseHas('habits', ['id' => $habit->id]);
    });
});

describe('Unauthenticated User', function (): void {
    test('guest cannot list habits', function (): void {
        $response = getJson(route('api.v1.habits.index'));
        $response->assertUnauthorized();
    });

    test('guest cannot create habit', function (): void {
        $response = postJson(route('api.v1.habits.store'), []);
        $response->assertUnauthorized();
    });

    test('guest cannot view habit', function (): void {
        $habit = Habit::factory()->create();
        $response = getJson(route('api.v1.habits.show', $habit));
        $response->assertUnauthorized();
    });

    test('guest cannot update habit', function (): void {
        $habit = Habit::factory()->create();
        $response = putJson(route('api.v1.habits.update', $habit), []);
        $response->assertUnauthorized();
    });

    test('guest cannot delete habit', function (): void {
        $habit = Habit::factory()->create();
        $response = deleteJson(route('api.v1.habits.destroy', $habit));
        $response->assertUnauthorized();
    });
});
