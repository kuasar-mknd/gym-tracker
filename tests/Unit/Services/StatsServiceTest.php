<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = new StatsService();
    }

    public function test_can_calculate_volume_trend(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        $trend = $this->statsService->getVolumeTrend($user);

        $this->assertCount(1, $trend);
        $this->assertEquals(1000, $trend[0]['volume']);
    }

    public function test_can_calculate_muscle_distribution(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['category' => 'Pectoraux']);
        $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        $dist = $this->statsService->getMuscleDistribution($user);

        $this->assertCount(1, $dist);
        $this->assertEquals('Pectoraux', $dist[0]->category);
        $this->assertEquals(1000, $dist[0]->volume);
    }

    public function test_can_calculate_monthly_volume_comparison(): void
    {
        $user = User::factory()->create();

        // Current month workout
        $workoutCurrent = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfMonth(),
        ]);
        $lineCurrent = WorkoutLine::factory()->create(['workout_id' => $workoutCurrent->id]);
        Set::factory()->create(['workout_line_id' => $lineCurrent->id, 'weight' => 50, 'reps' => 10]); // 500

        // Previous month workout
        $workoutPrev = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMonth()->startOfMonth(),
        ]);
        $linePrev = WorkoutLine::factory()->create(['workout_id' => $workoutPrev->id]);
        Set::factory()->create(['workout_line_id' => $linePrev->id, 'weight' => 40, 'reps' => 10]); // 400

        $comparison = $this->statsService->getMonthlyVolumeComparison($user);

        $this->assertEquals(500, $comparison['current_month_volume']);
        $this->assertEquals(400, $comparison['previous_month_volume']);
        $this->assertEquals(100, $comparison['difference']);
        $this->assertEquals(25.0, $comparison['percentage']);
    }

    public function test_can_calculate_weekly_volume_trend(): void
    {
        $user = User::factory()->create();

        // Create a workout for today (current week)
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfWeek(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]); // 1000

        $trend = $this->statsService->getWeeklyVolumeTrend($user);

        $this->assertCount(7, $trend); // Should always return 7 days (Mon-Sun)

        $targetDateStr = now()->startOfWeek()->format('Y-m-d');
        $found = false;

        foreach ($trend as $day) {
            if ($day['date'] === $targetDateStr) {
                $this->assertEquals(1000, $day['volume']);
                $found = true;
            } else {
                $this->assertEquals(0, $day['volume']);
            }
        }
        $this->assertTrue($found);
    }

    public function test_can_calculate_weekly_volume_comparison(): void
    {
        $user = User::factory()->create();

        // Current week workout
        $workoutCurrent = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfWeek(),
        ]);
        $lineCurrent = WorkoutLine::factory()->create(['workout_id' => $workoutCurrent->id]);
        Set::factory()->create(['workout_line_id' => $lineCurrent->id, 'weight' => 50, 'reps' => 10]); // 500

        // Previous week workout
        $workoutPrev = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subWeek()->startOfWeek(),
        ]);
        $linePrev = WorkoutLine::factory()->create(['workout_id' => $workoutPrev->id]);
        Set::factory()->create(['workout_line_id' => $linePrev->id, 'weight' => 40, 'reps' => 10]); // 400

        $comparison = $this->statsService->getWeeklyVolumeComparison($user);

        $this->assertEquals(500, $comparison['current_week_volume']);
        $this->assertEquals(400, $comparison['previous_week_volume']);
        $this->assertEquals(100, $comparison['difference']);
        $this->assertEquals(25.0, $comparison['percentage']);
    }
}
