<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\User;
use App\Models\Workout;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard displays correct workout stats', function (): void {
    $user = User::factory()->create();

    // Create 10 workouts from last month
    Workout::factory()->count(10)->create([
        'user_id' => $user->id,
        'started_at' => now()->subMonth(),
        'ended_at' => now()->subMonth()->addHour(),
    ]);

    // Create 3 workouts this week
    Workout::factory()->count(3)->create([
        'user_id' => $user->id,
        'started_at' => now()->startOfWeek()->addDay(),
        'ended_at' => now()->startOfWeek()->addDay()->addHour(),
    ]);

    // Create a body measurement
    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 75.5,
        'measured_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Dashboard')
                ->where('workoutsCount', 13)
                ->where('thisWeekCount', 3)
                ->where('latestWeight', '75.50')
                ->has('recentWorkouts', 3)
                // Deferred props should be missing from initial response
                ->missing('weeklyVolume')
                ->missing('volumeChange')
                ->missing('weeklyVolumeTrend')
                ->missing('volumeTrend')
                ->missing('durationDistribution')
        );
});
