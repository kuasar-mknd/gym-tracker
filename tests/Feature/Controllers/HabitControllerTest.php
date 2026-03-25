<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

it('renders the index page for authenticated user', function () {
    $user = User::factory()->create();
    Habit::factory()->count(3)->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('habits.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Habits/Index')
            ->has('habits')
            ->has('weekDates')
        );
});

it('prevents guests from accessing the index page', function () {
    $this->get(route('habits.index'))
        ->assertRedirect(route('login'));
});

it('creates a new habit', function () {
    $user = User::factory()->create();

    $payload = [
        'name' => 'Drink Water',
        'description' => 'Drink 2L of water',
        'color' => 'bg-blue-500',
        'icon' => 'water_drop',
        'goal_times_per_week' => 7,
    ];

    actingAs($user)
        ->post(route('habits.store'), $payload)
        ->assertRedirect()
        ->assertSessionHas('success', 'Habitude créée.');

    assertDatabaseHas('habits', [
        'user_id' => $user->id,
        'name' => 'Drink Water',
        'description' => 'Drink 2L of water',
        'color' => 'bg-blue-500',
        'icon' => 'water_drop',
        'goal_times_per_week' => 7,
        'archived' => false,
    ]);
});

it('validates habit creation payload', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('habits.store'), [])
        ->assertSessionHasErrors(['name', 'goal_times_per_week']);
});

it('updates an existing habit', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
    ]);

    $payload = [
        'name' => 'New Name',
        'description' => 'New Description',
        'goal_times_per_week' => 5,
        'color' => 'bg-red-500',
        'icon' => 'check',
    ];

    actingAs($user)
        ->put(route('habits.update', $habit), $payload)
        ->assertRedirect()
        ->assertSessionHas('success', 'Habitude mise à jour.');

    assertDatabaseHas('habits', [
        'id' => $habit->id,
        'name' => 'New Name',
        'description' => 'New Description',
        'goal_times_per_week' => 5,
    ]);
});

it('forbids updating another users habit', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user1->id]);

    actingAs($user2)
        ->put(route('habits.update', $habit), [
            'name' => 'Hacked Name',
            'goal_times_per_week' => 5,
        ])
        ->assertForbidden();
});

it('deletes a habit', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('habits.destroy', $habit))
        ->assertRedirect()
        ->assertSessionHas('success', 'Habitude supprimée.');

    assertDatabaseMissing('habits', ['id' => $habit->id]);
});

it('forbids deleting another users habit', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user1->id]);

    actingAs($user2)
        ->delete(route('habits.destroy', $habit))
        ->assertForbidden();

    assertDatabaseHas('habits', ['id' => $habit->id]);
});

it('toggles a habit (creates log)', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $date = '2023-10-25';

    actingAs($user)
        ->post(route('habits.toggle', $habit), ['date' => $date])
        ->assertRedirect();

    assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'date' => $date,
    ]);
});

it('toggles a habit (deletes log)', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $date = '2023-10-25';

    HabitLog::create([
        'habit_id' => $habit->id,
        'date' => $date,
    ]);

    actingAs($user)
        ->post(route('habits.toggle', $habit), ['date' => $date])
        ->assertRedirect();

    assertDatabaseMissing('habit_logs', [
        'habit_id' => $habit->id,
        'date' => $date,
    ]);
});

it('validates toggle payload requires date', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->post(route('habits.toggle', $habit), [])
        ->assertSessionHasErrors(['date']);
});

it('forbids toggling another users habit', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user1->id]);

    actingAs($user2)
        ->post(route('habits.toggle', $habit), ['date' => '2023-10-25'])
        ->assertForbidden();
});
