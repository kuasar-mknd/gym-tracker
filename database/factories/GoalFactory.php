<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'type' => fake()->randomElement(['weight', 'volume', 'frequency']),
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
