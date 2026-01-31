<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkoutsMonthlyVolumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_workouts_index_has_monthly_volume_prop(): void
    {
        $user = User::factory()->create();

        // Create a workout last month with volume
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMonth(),
            'ended_at' => now()->subMonth()->addHour(),
        ]);

        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
        ]);

        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10, // Volume = 1000
        ]);

        $this->actingAs($user)
            ->get('/workouts')
            ->assertStatus(200)
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Workouts/Index')
                    ->has('monthlyVolume')
                    ->where('monthlyVolume.4.volume', 1000) // 4th index is last month (5 months ago to current month = 6 items. Index 0=5 months ago, 5=current. Last month=4)
            );
    }
}
