<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('dashboard displays correct workout stats for populated user', function (): void {
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

    // Create active goals
    Goal::factory()->count(2)->create([
        'user_id' => $user->id,
        'completed_at' => null,
    ]);

    // Create completed goal (should not appear in active)
    Goal::factory()->create([
        'user_id' => $user->id,
        'completed_at' => now(),
    ]);

    // Create Personal Records
    PersonalRecord::factory()->count(2)->create([
        'user_id' => $user->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Dashboard')
            ->where('workoutsCount', 13) // 10 + 3
            ->where('thisWeekCount', 3)
            ->where('latestWeight', '75.50') // Decimal casting
            ->has('recentWorkouts', 3) // Action limits to 3
            ->has('recentPRs', 2)
            ->has('activeGoals', 2)
            ->has('weeklyVolume')
            ->has('volumeChange')
            ->has('weeklyVolumeTrend')
            ->has('volumeTrend')
            ->has('durationDistribution')
        );
});

test('dashboard handles new user with no data', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Dashboard')
            ->where('workoutsCount', 0)
            ->where('thisWeekCount', 0)
            ->where('latestWeight', null)
            ->has('recentWorkouts', 0)
            ->has('recentPRs', 0)
            ->has('activeGoals', 0)
            ->where('weeklyVolume', 0)
            ->where('volumeChange', 0)
            ->where('weeklyVolumeTrend.0.volume', 0) // Check first day is 0
            ->where('volumeTrend.0.volume', 0)
            ->has('durationDistribution')
        );
});

test('unauthenticated user is redirected to login', function (): void {
    get('/dashboard')
        ->assertRedirect(route('login'));
});

test('dashboard is resilient to extra query parameters', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/dashboard?period=invalid_garbage_value&foo=bar')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Dashboard')
            ->has('workoutsCount')
        );
});
