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

describe('Habits API', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can list habits', function (): void {
        Habit::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = getJson(route('api.v1.habits.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    });

    test('user can create habit', function (): void {
        $data = [
            'name' => 'Drink Water',
            'goal_times_per_week' => 7,
            'color' => '#0000FF',
            'icon' => 'water_drop',
        ];

        $response = postJson(route('api.v1.habits.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Drink Water'])
            ->assertJsonPath('data.goal_times_per_week', 7);

        assertDatabaseHas('habits', [
            'user_id' => $this->user->id,
            'name' => 'Drink Water',
        ]);
    });

    test('user can update habit', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $data = ['name' => 'Updated Name'];

        $response = putJson(route('api.v1.habits.update', $habit), $data);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        assertDatabaseHas('habits', [
            'id' => $habit->id,
            'name' => 'Updated Name',
        ]);
    });

    test('user can delete habit', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $response = deleteJson(route('api.v1.habits.destroy', $habit));

        $response->assertNoContent();

        assertDatabaseMissing('habits', ['id' => $habit->id]);
    });

    test('user cannot access others habits', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

        getJson(route('api.v1.habits.show', $habit))->assertForbidden();
        putJson(route('api.v1.habits.update', $habit), ['name' => 'Hack'])->assertForbidden();
        deleteJson(route('api.v1.habits.destroy', $habit))->assertForbidden();
    });

    test('store validates required fields', function (): void {
        $response = postJson(route('api.v1.habits.store'), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'goal_times_per_week']);
    });

    test('store validates numeric ranges', function (): void {
        $response = postJson(route('api.v1.habits.store'), [
            'name' => 'Bad Habit',
            'goal_times_per_week' => 8, // Max is 7
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['goal_times_per_week']);

        $response = postJson(route('api.v1.habits.store'), [
            'name' => 'Bad Habit',
            'goal_times_per_week' => 0, // Min is 1
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['goal_times_per_week']);
    });

    test('update validates numeric ranges', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $response = putJson(route('api.v1.habits.update', $habit), [
            'goal_times_per_week' => 8,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['goal_times_per_week']);
    });

    test('update allows partial updates', function (): void {
        $habit = Habit::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Old Name',
            'goal_times_per_week' => 5,
        ]);

        $response = putJson(route('api.v1.habits.update', $habit), [
            'goal_times_per_week' => 6,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Old Name') // Unchanged
            ->assertJsonPath('data.goal_times_per_week', 6);
    });
});

describe('Habit Logs API', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can create habit log', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $data = [
            'habit_id' => $habit->id,
            'date' => now()->format('Y-m-d'),
            'notes' => 'Drank 2L',
        ];

        $response = postJson(route('api.v1.habit-logs.store'), $data);

        $response->assertCreated();
        assertDatabaseHas('habit_logs', ['habit_id' => $habit->id]);
    });

    test('user cannot log for others habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

        $data = [
            'habit_id' => $habit->id,
            'date' => now()->format('Y-m-d'),
        ];

        $response = postJson(route('api.v1.habit-logs.store'), $data);

        $response->assertUnprocessable();
    });

    test('user can list habit logs', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);
        HabitLog::factory()->count(3)->create(['habit_id' => $habit->id]);

        $response = getJson(route('api.v1.habit-logs.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    });
});
