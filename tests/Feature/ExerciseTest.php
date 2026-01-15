<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseTest extends TestCase
{
    use RefreshDatabase;

    public function test_exercises_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/exercises');

        $response->assertOk();
    }

    public function test_can_create_exercise(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/exercises', [
            'name' => 'Développé couché',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('exercises', [
            'name' => 'Développé couché',
            'type' => 'strength',
            'category' => 'Pectoraux',
            'user_id' => $user->id,
        ]);
    }

    public function test_cannot_create_exercise_without_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/exercises', [
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_cannot_create_exercise_with_invalid_type(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/exercises', [
            'name' => 'Test Exercise',
            'type' => 'invalid',
            'category' => 'Pectoraux',
        ]);

        $response->assertSessionHasErrors('type');
    }

    public function test_can_update_own_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'name' => 'Old Name',
            'type' => 'strength',
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->put("/exercises/{$exercise->id}", [
            'name' => 'New Name',
            'type' => 'cardio',
            'category' => 'Cardio',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'name' => 'New Name',
            'type' => 'cardio',
            'category' => 'Cardio',
        ]);
    }

    public function test_cannot_update_system_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'name' => 'System Exercise',
            'type' => 'strength',
            'user_id' => null, // System exercise
        ]);

        $response = $this->actingAs($user)->put("/exercises/{$exercise->id}", [
            'name' => 'Hacked Name',
            'type' => 'strength',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'name' => 'System Exercise',
        ]);
    }

    public function test_can_delete_own_unused_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete("/exercises/{$exercise->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('exercises', [
            'id' => $exercise->id,
        ]);
    }

    public function test_cannot_delete_system_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => null,
        ]);

        $response = $this->actingAs($user)->delete("/exercises/{$exercise->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
        ]);
    }

    public function test_cannot_delete_exercise_in_use(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
        ]);
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $response = $this->actingAs($user)->delete("/exercises/{$exercise->id}");

        $response->assertRedirect();
        $response->assertSessionHasErrors('exercise');
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_exercises(): void
    {
        $response = $this->get('/exercises');

        $response->assertRedirect('/login');
    }
}
