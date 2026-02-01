<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->statsService = new StatsService();
    $this->user = User::factory()->create();
});

test('getDailyVolumeTrend returns correct volume for last X days', function (): void {
    // Create a workout yesterday
    $yesterday = now()->subDay();
    $workout = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => $yesterday,
    ]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 5,
    ]); // 500 volume

    // Create a workout today
    $today = now();
    $workout2 = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => $today,
    ]);
    $line2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id]);
    Set::factory()->create([
        'workout_line_id' => $line2->id,
        'weight' => 50,
        'reps' => 10,
    ]); // 500 volume

    $trend = $this->statsService->getDailyVolumeTrend($this->user, 7);

    expect($trend)->toHaveCount(7);

    // Verify yesterday
    $dayData = collect($trend)->firstWhere('date', $yesterday->format('d/m'));
    expect($dayData['volume'])->toBe(500.0);

    // Verify today
    $dayData = collect($trend)->firstWhere('date', $today->format('d/m'));
    expect($dayData['volume'])->toBe(500.0);

    // Verify a day with no workout (2 days ago)
    $twoDaysAgo = now()->subDays(2)->format('d/m');
    $dayData = collect($trend)->firstWhere('date', $twoDaysAgo);
    expect($dayData['volume'])->toBe(0.0);
});

test('getWeeklyVolumeTrend returns correct volume for current week', function (): void {
    $startOfWeek = now()->startOfWeek();

    // Monday workout
    $workout = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => $startOfWeek->copy()->addHour(),
    ]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10]); // 1000

    $trend = $this->statsService->getWeeklyVolumeTrend($this->user);

    expect($trend)->toHaveCount(7);
    expect($trend[0]['day_label'])->toBe('Lun');
    expect($trend[0]['volume'])->toBe(1000.0);
    expect($trend[1]['volume'])->toBe(0.0); // Tuesday
});

test('getWeeklyVolumeComparison calculates difference and percentage correctly', function (): void {
    // Current week: 1000 volume
    $workoutCurrent = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->startOfWeek()->addHour(),
    ]);
    $lineCurrent = WorkoutLine::factory()->create(['workout_id' => $workoutCurrent->id]);
    Set::factory()->create(['workout_line_id' => $lineCurrent->id, 'weight' => 100, 'reps' => 10]);

    // Previous week: 500 volume
    $workoutPrev = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subWeek()->startOfWeek()->addHour(),
    ]);
    $linePrev = WorkoutLine::factory()->create(['workout_id' => $workoutPrev->id]);
    Set::factory()->create(['workout_line_id' => $linePrev->id, 'weight' => 50, 'reps' => 10]);

    $comparison = $this->statsService->getWeeklyVolumeComparison($this->user);

    expect($comparison['current_week_volume'])->toBe(1000.0);
    expect($comparison['previous_week_volume'])->toBe(500.0);
    expect($comparison['difference'])->toBe(500.0);
    expect($comparison['percentage'])->toBe(100.0);
});

test('getVolumeHistory returns aggregated volume per workout', function (): void {
    $workout = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subDay(),
        'ended_at' => now()->subDay()->addHour(),
        'name' => 'Big Lift',
    ]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    // 2 sets
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 5]); // 500
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 5]); // 500
    // Total 1000

    $history = $this->statsService->getVolumeHistory($this->user);

    expect($history)->toHaveCount(1);
    expect($history[0]['name'])->toBe('Big Lift');
    expect($history[0]['volume'])->toBe(1000.0);
});

test('getDurationDistribution buckets workouts correctly', function (): void {
    // < 30 min
    Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(10),
        'ended_at' => now()->subHours(10)->addMinutes(20),
    ]);

    // 30-60 min
    Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(5),
        'ended_at' => now()->subHours(5)->addMinutes(45),
    ]);

    // 60-90 min
    Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2)->addMinutes(75),
    ]);

    // 90+ min
    Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subDay(),
        'ended_at' => now()->subDay()->addMinutes(100),
    ]);

    $dist = $this->statsService->getDurationDistribution($this->user);

    $under30 = collect($dist)->firstWhere('label', '< 30 min');
    $thirtySixty = collect($dist)->firstWhere('label', '30-60 min');
    $sixtyNinety = collect($dist)->firstWhere('label', '60-90 min');
    $ninetyPlus = collect($dist)->firstWhere('label', '90+ min');

    expect($under30['count'])->toBe(1);
    expect($thirtySixty['count'])->toBe(1);
    expect($sixtyNinety['count'])->toBe(1);
    expect($ninetyPlus['count'])->toBe(1);
});

test('getMonthlyVolumeHistory aggregates volume by month', function (): void {
    // This month
    $workout1 = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now(),
    ]);
    $line1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id]);
    Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 100, 'reps' => 10]); // 1000

    // Last month
    $workout2 = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subMonth(),
    ]);
    $line2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id]);
    Set::factory()->create(['workout_line_id' => $line2->id, 'weight' => 50, 'reps' => 10]); // 500

    $history = $this->statsService->getMonthlyVolumeHistory($this->user, 6);

    $thisMonthLabel = now()->translatedFormat('M');
    $lastMonthLabel = now()->subMonth()->translatedFormat('M');

    $thisMonthData = collect($history)->firstWhere('month', $thisMonthLabel);
    $lastMonthData = collect($history)->firstWhere('month', $lastMonthLabel);

    expect($thisMonthData['volume'])->toBe(1000.0);
    expect($lastMonthData['volume'])->toBe(500.0);
});

test('getWeightHistory returns body measurements', function (): void {
    BodyMeasurement::factory()->create([
        'user_id' => $this->user->id,
        'weight' => 80.0,
        'measured_at' => now()->subDays(5),
    ]);
    BodyMeasurement::factory()->create([
        'user_id' => $this->user->id,
        'weight' => 81.5,
        'measured_at' => now()->subDays(1),
    ]);

    $history = $this->statsService->getWeightHistory($this->user);

    expect($history)->toHaveCount(2);
    expect($history[0]['weight'])->toBe(80.0);
    expect($history[1]['weight'])->toBe(81.5);
});

test('getBodyFatHistory returns body fat measurements only', function (): void {
    // Measurement with body fat
    BodyMeasurement::factory()->create([
        'user_id' => $this->user->id,
        'body_fat' => 15.0,
        'measured_at' => now()->subDays(5),
    ]);
    // Measurement without body fat (should be excluded)
    BodyMeasurement::factory()->create([
        'user_id' => $this->user->id,
        'body_fat' => null,
        'measured_at' => now()->subDays(1),
    ]);

    $history = $this->statsService->getBodyFatHistory($this->user);

    expect($history)->toHaveCount(1);
    expect($history[0]['body_fat'])->toBe(15.0);
});

test('getLatestBodyMetrics calculates weight change correctly', function (): void {
    // Latest
    BodyMeasurement::factory()->create([
        'user_id' => $this->user->id,
        'weight' => 80.0,
        'body_fat' => 15.0,
        'measured_at' => now(),
    ]);
    // Previous
    BodyMeasurement::factory()->create([
        'user_id' => $this->user->id,
        'weight' => 82.5,
        'measured_at' => now()->subDay(),
    ]);

    $metrics = $this->statsService->getLatestBodyMetrics($this->user);

    expect($metrics['latest_weight'])->toBe(80.0);
    expect($metrics['latest_body_fat'])->toBe(15.0);
    // 80 - 82.5 = -2.5
    expect($metrics['weight_change'])->toBe(-2.5);
});

test('clearUserStatsCache clears cached values', function (): void {
    Cache::spy();

    $this->statsService->clearUserStatsCache($this->user);

    // Workout related
    Cache::shouldHaveReceived('forget')->with("stats.volume_trend.{$this->user->id}.30");
    Cache::shouldHaveReceived('forget')->with("stats.daily_volume.{$this->user->id}.7");
    Cache::shouldHaveReceived('forget')->with("dashboard_data_{$this->user->id}");
    Cache::shouldHaveReceived('forget')->with("stats.weekly_volume.{$this->user->id}");

    // Measurement related
    Cache::shouldHaveReceived('forget')->with("stats.weight_history.{$this->user->id}.30");
    Cache::shouldHaveReceived('forget')->with("stats.body_fat_history.{$this->user->id}.30");
});
