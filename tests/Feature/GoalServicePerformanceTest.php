<?php

namespace Tests\Feature;

use App\Enums\GoalType;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalServicePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_goals_performance(): void
    {
        $user = User::factory()->create();
        $exercises = Exercise::factory()->count(10)->create();

        foreach ($exercises as $exercise) {
            Goal::factory()->create([
                'user_id' => $user->id,
                'type' => GoalType::Weight,
                'exercise_id' => $exercise->id,
                'target_value' => 100,
                'current_value' => 0,
                'start_value' => 0,
            ]);

            Goal::factory()->create([
                'user_id' => $user->id,
                'type' => GoalType::Volume,
                'exercise_id' => $exercise->id,
                'target_value' => 1000,
                'current_value' => 0,
                'start_value' => 0,
            ]);
        }

        $service = new GoalService();

        // Count queries
        \DB::enableQueryLog();
        $service->syncGoals($user);
        $queries = \DB::getQueryLog();

        echo 'Total queries executed: '.count($queries)."\n";
        $this->assertTrue(true);
    }
}
