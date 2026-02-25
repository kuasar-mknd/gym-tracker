<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\Dashboard;

use App\Actions\Dashboard\FetchDashboardDataAction;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->action = app(FetchDashboardDataAction::class);
});

describe('getImmediateStats', function (): void {
    test('returns correct structure and values for happy path', function (): void {
        $user = User::factory()->create();

        // Workouts
        // 2 workouts this week
        Workout::factory()->count(2)->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfWeek()->addDay(),
        ]);
        // 1 workout last week
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subWeek()->startOfWeek(),
        ]);

        // Measurements
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 80.5,
            'measured_at' => now()->subDay(),
        ]);
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 79.5,
            'measured_at' => now(), // Latest
        ]);

        // Goals
        Goal::factory()->create(['user_id' => $user->id, 'completed_at' => null]);
        Goal::factory()->create(['user_id' => $user->id, 'completed_at' => null]);
        Goal::factory()->create(['user_id' => $user->id, 'completed_at' => now()]); // Completed, should be ignored

        // PRs
        $exercise = Exercise::factory()->create();
        PersonalRecord::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'achieved_at' => now()]);

        $stats = $this->action->getImmediateStats($user);

        expect($stats)
            ->toHaveKeys(['workoutsCount', 'thisWeekCount', 'latestWeight', 'recentWorkouts', 'recentPRs', 'activeGoals'])
            ->and($stats['workoutsCount'])->toBe(3)
            ->and($stats['thisWeekCount'])->toBe(2)
            ->and($stats['latestWeight'])->toBe('79.50')
            ->and($stats['activeGoals'])->toHaveCount(2)
            ->and($stats['recentPRs'])->toHaveCount(1)
            ->and($stats['recentWorkouts'])->toHaveCount(3);
    });

    test('limits recent items correctly', function (): void {
        $user = User::factory()->create();

        // Create 5 workouts
        Workout::factory()->count(5)->create(['user_id' => $user->id]);

        // Create 5 PRs
        $exercise = Exercise::factory()->create();
        PersonalRecord::factory()->count(5)->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);

        // Create 5 Goals
        Goal::factory()->count(5)->create(['user_id' => $user->id, 'completed_at' => null]);

        $stats = $this->action->getImmediateStats($user);

        expect($stats['recentWorkouts'])->toHaveCount(3)
            ->and($stats['recentPRs'])->toHaveCount(2)
            ->and($stats['activeGoals'])->toHaveCount(2);
    });

    test('handles no data gracefully', function (): void {
        $user = User::factory()->create();

        $stats = $this->action->getImmediateStats($user);

        expect($stats['workoutsCount'])->toBe(0)
            ->and($stats['thisWeekCount'])->toBe(0)
            ->and($stats['latestWeight'])->toBeNull()
            ->and($stats['recentWorkouts'])->toBeEmpty()
            ->and($stats['recentPRs'])->toBeEmpty()
            ->and($stats['activeGoals'])->toBeEmpty();
    });

    test('data isolation: does not return other users data', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Workout::factory()->create(['user_id' => $otherUser->id]);
        BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);
        Goal::factory()->create(['user_id' => $otherUser->id]);

        $stats = $this->action->getImmediateStats($user);

        expect($stats['workoutsCount'])->toBe(0)
            ->and($stats['latestWeight'])->toBeNull()
            ->and($stats['activeGoals'])->toBeEmpty();
    });
});

describe('getWeeklyVolumeStats', function (): void {
    test('calculates volume and percentage correctly', function (): void {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        // Current Week: 1000 volume
        $w1 = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->startOfWeek()]);
        $l1 = $w1->workoutLines()->create(['exercise_id' => $exercise->id]);
        $l1->sets()->create(['weight' => 100, 'reps' => 10]);

        // Last Week: 500 volume
        $w2 = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subWeek()->startOfWeek()]);
        $l2 = $w2->workoutLines()->create(['exercise_id' => $exercise->id]);
        $l2->sets()->create(['weight' => 50, 'reps' => 10]);

        $stats = $this->action->getWeeklyVolumeStats($user);

        // Diff = 1000 - 500 = 500. % = 500 / 500 * 100 = 100%
        expect($stats['current_week_volume'])->toBe(1000.0)
            ->and($stats['percentage'])->toBe(100.0);
    });

    test('handles zero previous volume (infinity case)', function (): void {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        // Current Week: 1000 volume
        $w1 = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->startOfWeek()]);
        $l1 = $w1->workoutLines()->create(['exercise_id' => $exercise->id]);
        $l1->sets()->create(['weight' => 100, 'reps' => 10]);

        // Last Week: 0 volume

        $stats = $this->action->getWeeklyVolumeStats($user);

        expect($stats['percentage'])->toBe(100.0); // Should be capped at 100 or handled as 100 increase
    });
});

describe('getWeeklyVolumeTrend', function (): void {
    test('returns array for 7 days', function (): void {
        $user = User::factory()->create();
        $trend = $this->action->getWeeklyVolumeTrend($user);

        expect($trend)->toHaveCount(7)
            ->and($trend[0])->toHaveKeys(['date', 'day_label', 'volume']);
    });
});

describe('getVolumeTrend', function (): void {
    test('returns array for days', function (): void {
        $user = User::factory()->create();
        $trend = $this->action->getVolumeTrend($user);

        expect($trend)->toBeArray()
            ->and($trend[0] ?? [])->toHaveKeys(['date', 'day_name', 'volume']);
    });
});

describe('getDurationDistribution', function (): void {
    test('returns distribution buckets', function (): void {
        $user = User::factory()->create();
        $dist = $this->action->getDurationDistribution($user);

        expect($dist)->toHaveCount(4) // <30, 30-60, 60-90, 90+
            ->and($dist[0])->toHaveKeys(['label', 'count']);
    });

    test('categorizes duration correctly', function (): void {
        $user = User::factory()->create();
        // 20 mins
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(40)]);
        // 45 mins
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subHours(2), 'ended_at' => now()->subHours(2)->addMinutes(45)]);

        $dist = $this->action->getDurationDistribution($user);

        $under30 = collect($dist)->firstWhere('label', '< 30 min')['count'];
        $between3060 = collect($dist)->firstWhere('label', '30-60 min')['count'];

        expect($under30)->toBe(1)
            ->and($between3060)->toBe(1);
    });
});
