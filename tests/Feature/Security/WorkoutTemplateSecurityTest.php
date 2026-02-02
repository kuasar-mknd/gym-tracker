<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTemplateSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_create_template_with_another_users_exercise(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();

        $victimExercise = Exercise::factory()->create([
            'user_id' => $victim->id,
            'name' => 'Victim Exercise',
        ]);

        // System exercise to ensure OR condition logic is tested
        $systemExercise = Exercise::factory()->create([
            'user_id' => null,
            'name' => 'System Exercise',
        ]);

        $this->actingAs($attacker);

        $response = $this->post(route('templates.store'), [
            'name' => 'Attacker Template',
            'exercises' => [
                [
                    'id' => $victimExercise->id,
                    'sets' => [['reps' => 10, 'weight' => 50]],
                ],
            ],
        ]);

        // Expect validation error (Safe)
        $response->assertSessionHasErrors(['exercises.0.id']);
    }
}
