<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_workout_template(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();

        $response = $this->post(route('templates.store'), [
            'name' => 'Test Template',
            'description' => 'Test Description',
            'exercises' => [
                [
                    'id' => $exercise->id,
                    'sets' => [
                        ['reps' => 10, 'weight' => 50, 'is_warmup' => false],
                        ['reps' => 8, 'weight' => 60, 'is_warmup' => false],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect(route('templates.index'));
        $this->assertDatabaseHas('workout_templates', ['name' => 'Test Template']);
        $this->assertDatabaseHas('workout_template_lines', ['exercise_id' => $exercise->id]);
        $this->assertDatabaseHas('workout_template_sets', ['reps' => 10, 'weight' => 50]);
    }

    public function test_can_execute_template_into_workout(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $template = $user->workoutTemplates()->create([
            'name' => 'Squat Day',
        ]);
        $line = $template->workoutTemplateLines()->create(['exercise_id' => $exercise->id]);
        $line->workoutTemplateSets()->create(['reps' => 5, 'weight' => 100, 'order' => 0]);

        $response = $this->post(route('templates.execute', $template));

        $response->assertRedirect();
        $this->assertDatabaseHas('workouts', ['name' => 'Squat Day', 'user_id' => $user->id]);
        $this->assertDatabaseHas('workout_lines', ['exercise_id' => $exercise->id]);
        $this->assertDatabaseHas('sets', ['reps' => 5, 'weight' => 100]);
    }

    public function test_can_save_workout_as_template(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = $user->workouts()->create([
            'name' => 'My Real Workout',
            'started_at' => now(),
        ]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id]);
        $line->sets()->create(['reps' => 12, 'weight' => 40]);

        $response = $this->post(route('templates.save-from-workout', $workout));

        $response->assertRedirect(route('templates.index'));
        $this->assertDatabaseHas('workout_templates', ['name' => 'My Real Workout (Modèle)']);
        $this->assertDatabaseHas('workout_template_lines', ['exercise_id' => $exercise->id]);
        $this->assertDatabaseHas('workout_template_sets', ['reps' => 12, 'weight' => 40]);
    }

    public function test_cannot_access_others_templates(): void
    {
        $user1 = \App\Models\User::factory()->create();
        $user2 = \App\Models\User::factory()->create();

        $template = $user1->workoutTemplates()->create([
            'name' => 'User 1 Template',
        ]);

        $this->actingAs($user2);

        $this->post(route('templates.execute', $template))->assertStatus(403);
        $this->delete(route('templates.destroy', $template))->assertStatus(403);
    }
}
