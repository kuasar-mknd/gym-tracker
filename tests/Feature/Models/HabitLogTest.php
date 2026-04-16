<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;

test('whereDateBetween scope works with separate arguments', function (): void {
    $habit = Habit::factory()->create();

    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-05']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-15']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-25']);

    $results = HabitLog::whereDateBetween('2023-01-10', '2023-01-20')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->date->toDateString())->toBe('2023-01-15');
});

test('whereDateBetween scope works with an array argument', function (): void {
    $habit = Habit::factory()->create();

    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-05']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-15']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-25']);

    $results = HabitLog::whereDateBetween(['2023-01-10', '2023-01-20'])->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->date->toDateString())->toBe('2023-01-15');
});

test('whereDateBetween scope ignores incomplete arguments', function (): void {
    $habit = Habit::factory()->create();

    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-05']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-15']);

    $results = HabitLog::whereDateBetween('2023-01-10')->get();

    expect($results)->toHaveCount(2); // Should not filter
});

test('whereDateBetween scope ignores single element array argument', function (): void {
    $habit = Habit::factory()->create();

    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-05']);
    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2023-01-15']);

    $results = HabitLog::whereDateBetween(['2023-01-10'])->get();

    expect($results)->toHaveCount(2); // Should not filter
});
