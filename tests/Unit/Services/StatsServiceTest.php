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

    public function test_can_get_volume_history(): void
    {
        $user = User::factory()->create();

        // Workout 1: 100kg * 10 reps = 1000
        $workout1 = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
            'name' => 'Workout 1',
        ]);
        $line1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id]);
        Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 100, 'reps' => 10]);

        // Workout 2: 50kg * 10 reps = 500
        $workout2 = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addHour(),
            'name' => 'Workout 2',
        ]);
        $line2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id]);
        Set::factory()->create(['workout_line_id' => $line2->id, 'weight' => 50, 'reps' => 10]);

        $history = $this->statsService->getVolumeHistory($user);

        $this->assertCount(2, $history);
        // History is returned oldest first (reversed latest)
        $this->assertEquals('Workout 1', $history[0]['name']);
        $this->assertEquals(1000, $history[0]['volume']);
        $this->assertEquals('Workout 2', $history[1]['name']);
        $this->assertEquals(500, $history[1]['volume']);
    }

    public function test_can_retrieve_duration_history(): void
    {
        $user = User::factory()->create();

        // Workout 1: 60 minutes
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2)->hour(10)->minute(0),
            'ended_at' => now()->subDays(2)->hour(11)->minute(0),
        ]);

        // Workout 2: 90 minutes
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay()->hour(10)->minute(0),
            'ended_at' => now()->subDay()->hour(11)->minute(30),
        ]);

        // Workout 3: 45 minutes, ended_at before started_at (should be handled as absolute)
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->hour(10)->minute(45),
            'ended_at' => now()->hour(10)->minute(0),
        ]);

        $history = $this->statsService->getDurationHistory($user);

        $this->assertCount(3, $history);

        // Check order (oldest first due to reverse())
        $this->assertEquals(60, $history[0]['duration']);
        $this->assertEquals(90, $history[1]['duration']);
        $this->assertEquals(45, $history[2]['duration']); // Should be absolute difference
    }

    public function test_can_calculate_duration_distribution(): void
    {
        $user = User::factory()->create();

        // < 30 min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(5)->hour(10)->minute(0),
            'ended_at' => now()->subDays(5)->hour(10)->minute(20),
        ]);

        // 30-60 min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(4)->hour(10)->minute(0),
            'ended_at' => now()->subDays(4)->hour(10)->minute(45),
        ]);

        // 60-90 min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(3)->hour(10)->minute(0),
            'ended_at' => now()->subDays(3)->hour(11)->minute(15),
        ]);

        // 90+ min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2)->hour(10)->minute(0),
            'ended_at' => now()->subDays(2)->hour(12)->minute(0),
        ]);

        // Outside of range (91 days ago)
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(91)->hour(10)->minute(0),
            'ended_at' => now()->subDays(91)->hour(11)->minute(0),
        ]);

        $dist = $this->statsService->getDurationDistribution($user);

        $this->assertCount(4, $dist);
        $this->assertEquals(1, $dist[0]['count']); // < 30
        $this->assertEquals('< 30 min', $dist[0]['label']);
        $this->assertEquals(1, $dist[1]['count']); // 30-60
        $this->assertEquals('30-60 min', $dist[1]['label']);
        $this->assertEquals(1, $dist[2]['count']); // 60-90
        $this->assertEquals('60-90 min', $dist[2]['label']);
        $this->assertEquals(1, $dist[3]['count']); // 90+
        $this->assertEquals('90+ min', $dist[3]['label']);
    }
}
