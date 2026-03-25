<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExerciseCategory;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseCategoryValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_exercise_with_all_valid_categories(): void
    {
        $user = User::factory()->create();

        foreach (ExerciseCategory::cases() as $category) {
            $response = $this->actingAs($user)->postJson('/api/v1/exercises', [
                'name' => "Exercise {$category->value}",
                'type' => 'strength',
                'category' => $category->value,
            ]);

            $response->assertCreated();

            $this->assertDatabaseHas('exercises', [
                'name' => "Exercise {$category->value}",
                'category' => $category->value,
            ]);

            // Ensure model can be queried and serialized without throwing ValueError
            $exercise = Exercise::where('name', "Exercise {$category->value}")->firstOrFail();
            $this->assertEquals($category, $exercise->category);
            $this->assertIsArray($exercise->toArray()); // Triggers the casts
        }
    }

    public function test_cannot_create_exercise_with_invalid_category(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/exercises', [
            'name' => 'Invalid Exercise',
            'type' => 'strength',
            'category' => 'InvalidCategory',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_cannot_update_exercise_with_invalid_category(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'category' => ExerciseCategory::Pectoraux->value,
        ]);

        $response = $this->actingAs($user)->putJson("/api/v1/exercises/{$exercise->id}", [
            'category' => 'InvalidCategory',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }
}
