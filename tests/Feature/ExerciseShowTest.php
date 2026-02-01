<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExerciseShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_exercise_show_page_displays_volume_history(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'ended_at' => now()->addHour(),
        ]);

        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        // 2 sets: 100kg x 5, 100kg x 5. Total volume = 500 + 500 = 1000.
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 5,
        ]);

        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 5,
        ]);

        $response = $this->actingAs($user)->get(route('exercises.show', $exercise));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Exercises/Show')
            ->has('history', 1)
            ->where('history.0.volume', 1000)
        );
    }
}
