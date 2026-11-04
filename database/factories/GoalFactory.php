<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoalType;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * Un objectif de charge ou de volume suit un exercice, un exercice du
     * catalogue : les deux formulaires l'exigent (#2164), et sans lui
     * l'objectif ne suit rien. Les autres types n'en ont pas. Un
     * `exercise_id` passé par un test, nul compris, reste le sien.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'type' => fake()->randomElement(['weight', 'volume', 'frequency']),
            'exercise_id' => static fn (array $attributs): ?ExerciseFactory => in_array(
                $attributs['type'] instanceof GoalType ? $attributs['type']->value : $attributs['type'],
                [GoalType::Weight->value, GoalType::Volume->value],
                true,
            ) ? Exercise::factory() : null,
            'target_value' => fake()->randomFloat(2, 50, 200),
            'current_value' => fake()->randomFloat(2, 0, 100),
            'start_value' => fake()->randomFloat(2, 0, 50),
            'deadline' => fake()->dateTimeBetween('now', '+6 months'),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
            'current_value' => $attributes['target_value'],
        ]);
    }
}
