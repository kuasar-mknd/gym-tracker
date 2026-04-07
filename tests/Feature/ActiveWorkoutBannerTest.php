<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;

it('shows active workout banner on dashboard when workout is in progress', function (): void {
    $user = User::factory()->create();

    $workout = Workout::factory()->for($user)->create([
        'name' => 'Push Day',
        'started_at' => now()->subMinutes(30),
        'ended_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('auth.user.active_workout')
            ->where('auth.user.active_workout.id', $workout->id)
            ->where('auth.user.active_workout.name', 'Push Day')
        );
});

it('does not show active workout when all workouts are finished', function (): void {
    $user = User::factory()->create();

    Workout::factory()->for($user)->create([
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.active_workout', null)
        );
});

it('shows most recent active workout when multiple exist', function (): void {
    $user = User::factory()->create();

    Workout::factory()->for($user)->create([
        'name' => 'Old Session',
        'started_at' => now()->subHours(3),
        'ended_at' => null,
    ]);

    $latest = Workout::factory()->for($user)->create([
        'name' => 'Latest Session',
        'started_at' => now()->subMinutes(10),
        'ended_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.active_workout.id', $latest->id)
            ->where('auth.user.active_workout.name', 'Latest Session')
        );
});
