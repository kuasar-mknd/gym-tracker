<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

test('authenticated user can view their habit logs', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    HabitLog::factory(3)->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->getJson('/api/v1/habit-logs')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'habit_id',
                    'date',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta',
            'links',
        ])
        ->assertJsonCount(3, 'data');
});

test('user cannot view another users habit logs', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherHabit = Habit::factory()->create(['user_id' => $otherUser->id]);
    HabitLog::factory()->create(['habit_id' => $otherHabit->id]);

    actingAs($user)
        ->getJson('/api/v1/habit-logs')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('authenticated user can create a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    $data = [
        'habit_id' => $habit->id,
        'date' => now()->format('Y-m-d'),
        'notes' => 'Completed my habit today',
    ];

    actingAs($user)
        ->postJson('/api/v1/habit-logs', $data)
        ->assertCreated()
        ->assertJsonPath('data.habit_id', $habit->id)
        ->assertJsonPath('data.date', $data['date'])
        ->assertJsonPath('data.notes', $data['notes']);

    assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'date' => $data['date'].' 00:00:00',
        'notes' => $data['notes'],
    ]);
});

test('cannot create a habit log with missing required fields', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/v1/habit-logs', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['habit_id', 'date']);
});

test('cannot create a habit log for another users habit', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherHabit = Habit::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->postJson('/api/v1/habit-logs', [
            'habit_id' => $otherHabit->id,
            'date' => now()->format('Y-m-d'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['habit_id']);
});

test('authenticated user can view a specific habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->getJson("/api/v1/habit-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $log->id);
});

test('cannot view another users habit log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherHabit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $otherHabit->id]);

    actingAs($user)
        ->getJson("/api/v1/habit-logs/{$log->id}")
        ->assertForbidden();
});

test('authenticated user can update a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    $data = [
        'notes' => 'Updated notes',
    ];

    actingAs($user)
        ->putJson("/api/v1/habit-logs/{$log->id}", $data)
        ->assertOk()
        ->assertJsonPath('data.notes', $data['notes']);

    assertDatabaseHas('habit_logs', [
        'id' => $log->id,
        'notes' => $data['notes'],
    ]);
});

test('cannot update another users habit log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherHabit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $otherHabit->id]);

    actingAs($user)
        ->putJson("/api/v1/habit-logs/{$log->id}", ['notes' => 'Updated notes'])
        ->assertForbidden();
});

test('authenticated user can delete a habit log', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->deleteJson("/api/v1/habit-logs/{$log->id}")
        ->assertNoContent();

    assertDatabaseMissing('habit_logs', [
        'id' => $log->id,
    ]);
});

test('cannot delete another users habit log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherHabit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $otherHabit->id]);

    actingAs($user)
        ->deleteJson("/api/v1/habit-logs/{$log->id}")
        ->assertForbidden();

    assertDatabaseHas('habit_logs', [
        'id' => $log->id,
    ]);
});
