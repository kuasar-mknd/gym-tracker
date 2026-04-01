<?php

declare(strict_types=1);

use App\Actions\Habits\CreateHabitAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('it creates a new habit with the provided data', function (): void {
    $user = User::factory()->create();
    $action = new CreateHabitAction();
    $data = [
        'name' => 'Drink Water',
        'description' => 'Drink 8 glasses of water daily',
        'color' => 'bg-blue-500',
        'icon' => 'water_drop',
        'goal_times_per_week' => 7,
    ];

    $habit = $action->execute($user, $data);

    expect($habit->name)->toBe('Drink Water')
        ->and($habit->description)->toBe('Drink 8 glasses of water daily')
        ->and($habit->color)->toBe('bg-blue-500')
        ->and($habit->icon)->toBe('water_drop')
        ->and($habit->goal_times_per_week)->toBe(7)
        ->and($habit->user_id)->toBe($user->id);

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'user_id' => $user->id,
        'name' => 'Drink Water',
        'color' => 'bg-blue-500',
        'icon' => 'water_drop',
    ]);
});

test('it sets a default color and icon if they are missing', function (): void {
    $user = User::factory()->create();
    $action = new CreateHabitAction();
    $data = [
        'name' => 'Read',
    ];

    $habit = $action->execute($user, $data);

    expect($habit->name)->toBe('Read')
        ->and($habit->color)->toBe('bg-slate-500')
        ->and($habit->icon)->toBe('check_circle')
        ->and($habit->user_id)->toBe($user->id);

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'user_id' => $user->id,
        'name' => 'Read',
        'color' => 'bg-slate-500',
        'icon' => 'check_circle',
    ]);
});

test('it overrides null color and icon with defaults', function (): void {
    $user = User::factory()->create();
    $action = new CreateHabitAction();
    $data = [
        'name' => 'Exercise',
        'color' => null,
        'icon' => null,
    ];

    $habit = $action->execute($user, $data);

    expect($habit->name)->toBe('Exercise')
        ->and($habit->color)->toBe('bg-slate-500')
        ->and($habit->icon)->toBe('check_circle')
        ->and($habit->user_id)->toBe($user->id);

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'user_id' => $user->id,
        'name' => 'Exercise',
        'color' => 'bg-slate-500',
        'icon' => 'check_circle',
    ]);
});

test('it overrides empty string color and icon with defaults', function (): void {
    $user = User::factory()->create();
    $action = new CreateHabitAction();
    $data = [
        'name' => 'Meditate',
        'color' => '',
        'icon' => '',
    ];

    $habit = $action->execute($user, $data);

    expect($habit->name)->toBe('Meditate')
        ->and($habit->color)->toBe('bg-slate-500')
        ->and($habit->icon)->toBe('check_circle')
        ->and($habit->user_id)->toBe($user->id);

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'user_id' => $user->id,
        'name' => 'Meditate',
        'color' => 'bg-slate-500',
        'icon' => 'check_circle',
    ]);
});
