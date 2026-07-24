<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkoutTemplateSet>
 */
class WorkoutTemplateSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workout_template_line_id' => \App\Models\WorkoutTemplateLine::factory(),
            'reps' => $this->faker->numberBetween(1, 15),
            'weight' => $this->faker->randomFloat(2, 5, 100),
            'is_warmup' => false,
            'order' => $this->faker->numberBetween(0, 5),
        ];
    }
}
