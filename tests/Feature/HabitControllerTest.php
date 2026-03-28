<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

describe('HabitController Index', function (): void {
    it('allows an authenticated user to view their habits', function (): void {
        Habit::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get(route('habits.index'));

        $response->assertOk();
    });

    it('prevents guests from viewing habits', function (): void {
        $response = $this->get(route('habits.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('HabitController Store', function (): void {
    it('allows a user to create a habit', function (): void {
        $habitData = [
            'name' => 'Drink Water',
            'description' => 'Drink 2L of water daily',
            'color' => 'bg-blue-500',
            'icon' => 'water_drop',
            'goal_times_per_week' => 7,
            'archived' => false,
        ];

        $response = $this->actingAs($this->user)->post(route('habits.store'), $habitData);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Habitude créée.');

        $this->assertDatabaseHas('habits', [
            'user_id' => $this->user->id,
            'name' => 'Drink Water',
            'goal_times_per_week' => 7,
        ]);
    });

    it('returns validation error if name is missing', function (): void {
        $habitData = [
            'goal_times_per_week' => 7,
        ];

        $response = $this->actingAs($this->user)->post(route('habits.store'), $habitData);

        $response->assertInvalid(['name']);
        $this->assertDatabaseCount('habits', 0);
    });

    it('returns validation error if goal_times_per_week is invalid', function (): void {
        $habitData = [
            'name' => 'Test Habit',
            'goal_times_per_week' => 8, // Over max 7
        ];

        $response = $this->actingAs($this->user)->post(route('habits.store'), $habitData);

        $response->assertInvalid(['goal_times_per_week']);
        $this->assertDatabaseCount('habits', 0);
    });
});

describe('HabitController Update', function (): void {
    it('allows a user to update their own habit', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $updateData = [
            'name' => 'Updated Habit Name',
            'goal_times_per_week' => 5,
        ];

        $response = $this->actingAs($this->user)->put(route('habits.update', $habit), $updateData);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Habitude mise à jour.');

        $this->assertDatabaseHas('habits', [
            'id' => $habit->id,
            'name' => 'Updated Habit Name',
            'goal_times_per_week' => 5,
        ]);
    });

    it('returns validation error if updated goal_times_per_week is invalid', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $updateData = [
            'goal_times_per_week' => 0, // Under min 1
        ];

        $response = $this->actingAs($this->user)->put(route('habits.update', $habit), $updateData);

        $response->assertInvalid(['goal_times_per_week']);
    });

    it('prevents a user from updating another user\'s habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

        $updateData = [
            'name' => 'Hacked Habit',
        ];

        $response = $this->actingAs($this->user)->put(route('habits.update', $habit), $updateData);

        $response->assertForbidden();

        $this->assertDatabaseMissing('habits', [
            'id' => $habit->id,
            'name' => 'Hacked Habit',
        ]);
    });
});

describe('HabitController Destroy', function (): void {
    it('allows a user to delete their own habit', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->delete(route('habits.destroy', $habit));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Habitude supprimée.');

        $this->assertDatabaseMissing('habits', [
            'id' => $habit->id,
        ]);
    });

    it('prevents a user from deleting another user\'s habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->delete(route('habits.destroy', $habit));

        $response->assertForbidden();

        $this->assertDatabaseHas('habits', [
            'id' => $habit->id,
        ]);
    });
});

describe('HabitController Toggle', function (): void {
    it('creates a log if it does not exist for the date (checks the habit)', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);
        $date = Carbon::today()->format('Y-m-d');

        $response = $this->actingAs($this->user)->post(route('habits.toggle', $habit), [
            'date' => $date,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('habit_logs', [
            'habit_id' => $habit->id,
            'date' => $date,
        ]);
    });

    it('deletes a log if it exists for the date (unchecks the habit)', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);
        $date = Carbon::today()->format('Y-m-d');

        HabitLog::create([
            'habit_id' => $habit->id,
            'date' => $date,
        ]);

        $response = $this->actingAs($this->user)->post(route('habits.toggle', $habit), [
            'date' => $date,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('habit_logs', [
            'habit_id' => $habit->id,
            'date' => $date,
        ]);
    });

    it('returns validation error for toggle if date is missing or invalid', function (): void {
        $habit = Habit::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->post(route('habits.toggle', $habit), [
            'date' => 'invalid-date-string',
        ]);

        $response->assertInvalid(['date']);
    });

    it('prevents a user from toggling another user\'s habit', function (): void {
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
        $date = Carbon::today()->format('Y-m-d');

        $response = $this->actingAs($this->user)->post(route('habits.toggle', $habit), [
            'date' => $date,
        ]);

        $response->assertForbidden();
    });
});
