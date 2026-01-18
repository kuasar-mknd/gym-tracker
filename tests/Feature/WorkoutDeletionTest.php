<?php

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can delete its own workout', function () {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create(['workout_line_id' => $line->id]);

    actingAs($this->user)
        ->delete(route('workouts.destroy', $workout))
        ->assertRedirect(route('workouts.index'));

    $this->assertDatabaseMissing('workouts', ['id' => $workout->id]);
    $this->assertDatabaseMissing('workout_lines', ['id' => $line->id]);
});

it('cannot delete someone else\'s workout', function () {
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    actingAs($this->user)
        ->delete(route('workouts.destroy', $workout))
        ->assertForbidden();

    $this->assertDatabaseHas('workouts', ['id' => $workout->id]);
});

it('can update its workout date and name', function () {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $newDate = '2025-01-01 10:00:00';
    $newName = 'New Workout Name';

    actingAs($this->user)
        ->patch(route('workouts.update', $workout), [
            'started_at' => $newDate,
            'name' => $newName,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'started_at' => $newDate,
        'name' => $newName,
    ]);
});
