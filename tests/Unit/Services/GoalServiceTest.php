<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GoalService $goalService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->goalService = new GoalService();
    }

    public function test_sync_goals_only_updates_incomplete_goals(): void
    {
        $user = User::factory()->create();

        // Incomplete goal
        $incompleteGoal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'frequency',
            'current_value' => 0,
            'completed_at' => null,
        ]);

        // Completed goal
        $completedGoal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'frequency',
            'current_value' => 5,
            'completed_at' => now()->subDay(),
        ]);

        // Create 2 workouts for the user
        Workout::factory()->count(2)->create(['user_id' => $user->id]);

        $this->goalService->syncGoals($user);

        $this->assertEquals(2, $incompleteGoal->fresh()->current_value);
        $this->assertEquals(5, $completedGoal->fresh()->current_value); // Should remain unchanged
    }

    public function test_update_weight_goal_handles_null_exercise_id(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'weight',
            'exercise_id' => null,
            'current_value' => 10,
        ]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertEquals(10, $goal->fresh()->current_value);
    }

    public function test_update_weight_goal_calculates_max_weight(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'weight',
            'exercise_id' => $exercise->id,
            'current_value' => 0,
        ]);

        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 50]);
        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80]);
        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 60]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertEquals(80, $goal->fresh()->current_value);
    }

    public function test_sync_goals_handles_no_goals(): void
    {
        $user = User::factory()->create();

        $this->goalService->syncGoals($user);

        $this->assertTrue(true);
    }

    public function test_update_frequency_goal_counts_all_workouts(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'frequency',
            'current_value' => 0,
        ]);

        Workout::factory()->count(3)->create(['user_id' => $user->id]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertEquals(3, $goal->fresh()->current_value);
    }

    public function test_update_volume_goal_handles_null_exercise_id(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'volume',
            'exercise_id' => null,
            'current_value' => 100,
        ]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertEquals(100, $goal->fresh()->current_value);
    }

    public function test_update_volume_goal_calculates_max_single_workout_volume(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'volume',
            'exercise_id' => $exercise->id,
            'current_value' => 0,
        ]);

        // Workout 1: Volume = (50*10) + (50*10) = 1000
        $workout1 = Workout::factory()->create(['user_id' => $user->id]);
        $line1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id, 'exercise_id' => $exercise->id]);
        Set::factory()->count(2)->create(['workout_line_id' => $line1->id, 'weight' => 50, 'reps' => 10]);

        // Workout 2: Volume = (60*10) + (60*10) = 1200
        $workout2 = Workout::factory()->create(['user_id' => $user->id]);
        $line2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id, 'exercise_id' => $exercise->id]);
        Set::factory()->count(2)->create(['workout_line_id' => $line2->id, 'weight' => 60, 'reps' => 10]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertEquals(1200, $goal->fresh()->current_value);
    }

    public function test_update_measurement_goal_picks_latest_value(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'measurement',
            'measurement_type' => 'weight',
            'current_value' => 0,
        ]);

        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 85,
            'measured_at' => now()->subDays(2),
        ]);

        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 82,
            'measured_at' => now()->subDay(),
        ]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertEquals(82, $goal->fresh()->current_value);
    }

    public function test_check_completion_sets_completed_at_when_target_reached(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'frequency',
            'target_value' => 2,
            'current_value' => 0,
            'completed_at' => null,
        ]);

        // Create 2 workouts to reach the target
        Workout::factory()->count(2)->create(['user_id' => $user->id]);

        $this->goalService->updateGoalProgress($goal);

        $this->assertNotNull($goal->fresh()->completed_at);
    }

    public function test_check_completion_resets_completed_at_when_below_target(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'frequency',
            'target_value' => 5,
            'current_value' => 5,
            'completed_at' => now(),
        ]);

        // No workouts created, so updateGoalProgress will set current_value to 0
        $this->goalService->updateGoalProgress($goal);

        $this->assertNull($goal->fresh()->completed_at);
    }

    public function test_is_goal_criteria_met_for_lower_is_better_scenarios(): void
    {
        $user = User::factory()->create();

        // Weight loss goal: target 80, start 90
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'measurement',
            'measurement_type' => 'weight',
            'start_value' => 90,
            'target_value' => 80,
            'current_value' => 0,
            'completed_at' => null,
        ]);

        // First measurement: 81 (not reached)
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 81,
            'measured_at' => now(),
        ]);
        $this->goalService->updateGoalProgress($goal);
        $this->assertNull($goal->fresh()->completed_at);

        // Second measurement: 80 (reached)
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 80,
            'measured_at' => now()->addDay(),
        ]);
        $this->goalService->updateGoalProgress($goal);
        $this->assertNotNull($goal->fresh()->completed_at);
    }
}
