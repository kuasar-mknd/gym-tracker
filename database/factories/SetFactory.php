<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Set>
 */
class SetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workout_line_id' => \App\Models\WorkoutLine::factory(),
            'weight' => $this->faker->randomFloat(1, 0, 200),
            'reps' => $this->faker->numberBetween(1, 20),
            'is_warmup' => false,
        ];
    }
}
