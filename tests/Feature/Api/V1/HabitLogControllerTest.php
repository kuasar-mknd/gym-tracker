<?php

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    // Setup if needed, but factories handle most things
});

test('user can list their habit logs', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    HabitLog::factory()->count(3)->create(['habit_id' => $habit->id]);

    // Create a log for another user to ensure strict filtering
    $otherUser = User::factory()->create();
    $otherHabit = Habit::factory()->create(['user_id' => $otherUser->id]);
    HabitLog::factory()->create(['habit_id' => $otherHabit->id]);

    actingAs($user)
        ->getJson(route('api.v1.habit-logs.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'habit_id', 'date', 'notes']]]);
});

test('user can list habit logs filtered by habit_id', function () {
    $user = User::factory()->create();
    $habit1 = Habit::factory()->create(['user_id' => $user->id]);
    $habit2 = Habit::factory()->create(['user_id' => $user->id]);

    HabitLog::factory()->create(['habit_id' => $habit1->id]);
    HabitLog::factory()->count(2)->create(['habit_id' => $habit2->id]);

    actingAs($user)
        ->getJson(route('api.v1.habit-logs.index', ['filter[habit_id]' => $habit2->id]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can view a specific habit log', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->getJson(route('api.v1.habit-logs.show', $log))
        ->assertOk()
        ->assertJsonFragment(['id' => $log->id]);
});

test('user cannot view another users habit log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->getJson(route('api.v1.habit-logs.show', $log))
        ->assertForbidden();
});

test('user gets 404 for non-existent habit log', function () {
    $user = User::factory()->create();
    actingAs($user)
        ->getJson(route('api.v1.habit-logs.show', 99999))
        ->assertNotFound();
});

test('user can create a habit log', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    $data = [
        'habit_id' => $habit->id,
        'date' => '2023-10-10',
        'notes' => 'Great success',
    ];

    actingAs($user)
        ->postJson(route('api.v1.habit-logs.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['notes' => 'Great success']);

    $this->assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'date' => '2023-10-10',
    ]);
});

test('user cannot create a habit log for another users habit', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'habit_id' => $habit->id,
        'date' => '2023-10-10',
    ];

    actingAs($user)
        ->postJson(route('api.v1.habit-logs.store'), $data)
        ->assertUnprocessable(); // Validation should fail due to 'exists' rule checking ownership
});

test('validation fails for invalid data', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.habit-logs.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['habit_id', 'date']);
});

test('user can update their habit log', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    $data = ['notes' => 'Updated notes'];

    actingAs($user)
        ->putJson(route('api.v1.habit-logs.update', $log), $data)
        ->assertOk()
        ->assertJsonFragment(['notes' => 'Updated notes']);

    $this->assertDatabaseHas('habit_logs', [
        'id' => $log->id,
        'notes' => 'Updated notes',
    ]);
});

test('user cannot update another users habit log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->putJson(route('api.v1.habit-logs.update', $log), ['notes' => 'Hacked'])
        ->assertForbidden();
});

test('user can delete their habit log', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.habit-logs.destroy', $log))
        ->assertNoContent();

    $this->assertDatabaseMissing('habit_logs', ['id' => $log->id]);
});

test('user cannot delete another users habit log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
    $log = HabitLog::factory()->create(['habit_id' => $habit->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.habit-logs.destroy', $log))
        ->assertForbidden();
});
