<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

// Happy Path Tests

test('user can list habit logs', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    HabitLog::factory()->count(3)->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.habit-logs.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can filter habit logs by habit', function (): void {
    $user = User::factory()->create();
    $habit1 = Habit::factory()->create(['user_id' => $user->id]);
    $habit2 = Habit::factory()->create(['user_id' => $user->id]);

    HabitLog::factory()->create(['habit_id' => $habit1->id]);
    HabitLog::factory()->create(['habit_id' => $habit2->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.habit-logs.index', [
            'filter[habit_id]' => $habit1->id,
            'include' => 'habit',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.habit.id', $habit1->id);
});

test('user can filter habit logs by date range', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-01']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-15']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-02-01']);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.habit-logs.index', [
            'filter[date_between]' => ['2023-01-01', '2023-01-31'],
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can create a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    $data = [
        'habit_id' => $habit->id,
        'date' => now()->toDateString(),
        'notes' => 'Completed properly',
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.habit-logs.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.notes', 'Completed properly');

    assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'notes' => 'Completed properly',
    ]);
});

test('user can show a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.habit-logs.show', $log))
        ->assertOk()
        ->assertJsonPath('data.id', $log->id);
});

test('user can update a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id, 'notes' => 'Old note']);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.habit-logs.update', $log), [
            'notes' => 'New note',
        ])
        ->assertOk()
        ->assertJsonPath('data.notes', 'New note');

    assertDatabaseHas('habit_logs', [
        'id' => $log->id,
        'notes' => 'New note',
    ]);
});

test('user can delete a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.habit-logs.destroy', $log))
        ->assertNoContent();

    assertDatabaseMissing('habit_logs', ['id' => $log->id]);
});

// Validation Tests

test('store requires mandatory fields', function (): void {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.habit-logs.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['habit_id', 'date']);
});

test('store validates habit ownership', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.habit-logs.store'), [
            'habit_id' => $habit->id,
            'date' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['habit_id']);
});

test('store validates date format', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.habit-logs.store'), [
            'habit_id' => $habit->id,
            'date' => 'invalid-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('update validates notes max length', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.habit-logs.update', $log), [
            'notes' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notes']);
});

// Authorization Tests

test('guest cannot access endpoints', function (): void {
    $habit = Habit::factory()->create();
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    $this->getJson(route('api.v1.habit-logs.index'))->assertUnauthorized();
    $this->postJson(route('api.v1.habit-logs.store'), [])->assertUnauthorized();
    $this->getJson(route('api.v1.habit-logs.show', $log))->assertUnauthorized();
    $this->putJson(route('api.v1.habit-logs.update', $log), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.habit-logs.destroy', $log))->assertUnauthorized();
});

test('user cannot view other user habit log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.habit-logs.show', $log))
        ->assertForbidden();
});

test('user cannot update other user habit log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.habit-logs.update', $log), [
            'notes' => 'Hacked',
        ])
        ->assertForbidden();
});

test('user cannot delete other user habit log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.habit-logs.destroy', $log))
        ->assertForbidden();
});
