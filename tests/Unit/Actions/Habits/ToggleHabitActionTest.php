<?php

declare(strict_types=1);

use App\Actions\Habits\ToggleHabitAction;
use App\Models\Habit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('it creates a new log if one does not exist for the given date', function (): void {
    // Arrange
    $action = new ToggleHabitAction();
    $habit = Habit::factory()->create();
    $date = '2023-10-25';

    // Act
    $action->execute($habit, $date);

    // Assert
    $this->assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'date' => clone Carbon::parse($date)->startOfDay(),
    ]);
});

test('it deletes the log if one already exists for the given date', function (): void {
    // Arrange
    $action = new ToggleHabitAction();
    $habit = Habit::factory()->create();
    $date = '2023-10-25';

    // Create an initial log
    $habit->logs()->create([
        'date' => clone Carbon::parse($date)->startOfDay(),
    ]);

    // Ensure it exists before acting
    $this->assertDatabaseHas('habit_logs', [
        'habit_id' => $habit->id,
        'date' => clone Carbon::parse($date)->startOfDay(),
    ]);

    // Act
    $action->execute($habit, $date);

    // Assert
    $this->assertDatabaseMissing('habit_logs', [
        'habit_id' => $habit->id,
        'date' => clone Carbon::parse($date)->startOfDay(),
    ]);
});
