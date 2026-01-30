<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_a_set_creates_personal_records(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $this->post(route('sets.store', $workoutLine), [
            'reps' => 10,
            'weight' => 50,
        ]);

        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 50,
        ]);

        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'type' => 'max_1rm',
            'value' => 66.67, // 50 * (1 + 10/30)
        ]);
    }

    public function test_updating_a_set_updates_personal_record(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $set = \App\Models\Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'reps' => 10,
            'weight' => 50,
        ]);

        // Manually trigger service since factory doesn't
        (new \App\Services\PersonalRecordService())->syncSetPRs($set);

        $this->patch(route('sets.update', $set), [
            'reps' => 10,
            'weight' => 60,
        ]);

        $this->assertDatabaseHas('personal_records', [
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 60,
        ]);
    }

    public function test_lower_weight_does_not_overwrite_pr(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        // Create initial PR
        $user->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $this->post(route('sets.store', $workoutLine), [
            'reps' => 10,
            'weight' => 50,
        ]);

        $this->assertDatabaseHas('personal_records', [
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 100,
        ]);
    }
}
