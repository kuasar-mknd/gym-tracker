<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\CreateWorkoutTemplateAction;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreateWorkoutTemplateActionBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_benchmark()
    {
        $user = User::factory()->create();
        $action = app(CreateWorkoutTemplateAction::class);
        $exercises = Exercise::factory()->count(100)->create();

        $data = [
            'name' => 'Big Workout',
            'exercises' => [],
        ];

        foreach ($exercises as $index => $ex) {
            $data['exercises'][] = [
                'id' => $ex->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 50.0],
                    ['reps' => 10, 'weight' => 50.0],
                    ['reps' => 10, 'weight' => 50.0],
                ]
            ];
        }

        DB::enableQueryLog();

        $start = microtime(true);
        $action->execute($user, $data);
        $end = microtime(true);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue(true);
        echo "Queries executed: " . count($queries) . "\n";
        echo "Time taken: " . ($end - $start) . " seconds\n";
    }
}
