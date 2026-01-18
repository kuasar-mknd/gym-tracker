<?php

use App\Models\Habit;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('user can view habits page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('habits.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Habits/Index')
            ->has('habits')
            ->has('weekDates')
        );
});

test('user can create a habit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('habits.store'), [
            'name' => 'Drink Water',
            'goal_times_per_week' => 7,
            'color' => 'bg-blue-500',
            'icon' => 'water_drop',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('habits', [
        'user_id' => $user->id,
        'name' => 'Drink Water',
        'goal_times_per_week' => 7,
    ]);
});

test('user can update a habit', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->put(route('habits.update', $habit), [
            'name' => 'Updated Name',
            'goal_times_per_week' => 5,
            'color' => 'bg-red-500',
            'icon' => 'edit',
            'archived' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'name' => 'Updated Name',
        'goal_times_per_week' => 5,
    ]);
});

test('user can delete a habit', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('habits.destroy', $habit))
        ->assertRedirect();

    $this->assertDatabaseMissing('habits', ['id' => $habit->id]);
});

test('user can toggle a habit', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $date = now()->toDateString();

    // Toggle on
    $this->actingAs($user)
        ->post(route('habits.toggle', $habit), ['date' => $date])
        ->assertRedirect();

    $log = \App\Models\HabitLog::where('habit_id', $habit->id)->first();
    expect($log)->not->toBeNull();
    expect($log->date->toDateString())->toBe($date);

    // Toggle off
    $this->actingAs($user)
        ->post(route('habits.toggle', $habit), ['date' => $date])
        ->assertRedirect();

    expect(\App\Models\HabitLog::where('habit_id', $habit->id)->whereDate('date', $date)->exists())->toBeFalse();
});

test('user cannot update other users habits', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->put(route('habits.update', $habit), [
            'name' => 'Hacked',
            'goal_times_per_week' => 5,
        ])
        ->assertForbidden();
});

test('user cannot toggle other users habits', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->post(route('habits.toggle', $habit), ['date' => now()->toDateString()])
        ->assertForbidden();
});
