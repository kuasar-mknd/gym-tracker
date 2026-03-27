<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('displays the habits index page for the user', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('habits.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Habits/Index')
            ->has('habits', 1)
            ->where('habits.0.id', $habit->id)
        );
});

it('cannot view habits if not authenticated', function (): void {
    get(route('habits.index'))
        ->assertRedirect(route('login'));
});

it('can store a new habit', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('habits.store'), [
            'name' => 'Read a book',
            'description' => 'Read 10 pages',
            'color' => 'bg-blue-500',
            'icon' => 'book',
            'goal_times_per_week' => 5,
            'archived' => false,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Habitude créée.');

    $this->assertDatabaseHas('habits', [
        'user_id' => $user->id,
        'name' => 'Read a book',
        'goal_times_per_week' => 5,
    ]);
});

it('fails to store habit with invalid data', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('habits.store'), [
            'name' => '', // required
            'goal_times_per_week' => 10, // max 7
        ])
        ->assertSessionHasErrors(['name', 'goal_times_per_week']);
});

it('can update an existing habit', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

    actingAs($user)
        ->put(route('habits.update', $habit), [
            'name' => 'New Name',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Habitude mise à jour.');

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'name' => 'New Name',
    ]);
});

it('fails to update habit with invalid data', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->put(route('habits.update', $habit), [
            'goal_times_per_week' => 8, // max 7
        ])
        ->assertSessionHasErrors(['goal_times_per_week']);
});

it('cannot update someone elses habit', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user2->id]);

    actingAs($user1)
        ->put(route('habits.update', $habit), [
            'name' => 'Hacked Name',
        ])
        ->assertForbidden();
});

it('can delete a habit', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('habits.destroy', $habit))
        ->assertRedirect()
        ->assertSessionHas('success', 'Habitude supprimée.');

    $this->assertDatabaseMissing('habits', [
        'id' => $habit->id,
    ]);
});

it('cannot delete someone elses habit', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user2->id]);

    actingAs($user1)
        ->delete(route('habits.destroy', $habit))
        ->assertForbidden();

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
    ]);
});

it('can toggle a habit for a date', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->post(route('habits.toggle', $habit), [
            'date' => '2023-10-15',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'date' => '2023-10-15',
    ]);
});

it('cannot toggle someone elses habit', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user2->id]);

    actingAs($user1)
        ->post(route('habits.toggle', $habit), [
            'date' => '2023-10-15',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('habit_logs', [
        'habit_id' => $habit->id,
        'date' => '2023-10-15',
    ]);
});

it('fails to toggle habit without a date', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->post(route('habits.toggle', $habit), [])
        ->assertSessionHasErrors(['date']);
});
