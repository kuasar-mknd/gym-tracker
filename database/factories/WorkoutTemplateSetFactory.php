<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkoutTemplateLine;
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
            'workout_template_line_id' => WorkoutTemplateLine::factory(),
            'reps' => fake()->numberBetween(1, 15),
            'weight' => fake()->randomFloat(2, 5, 100),
            'is_warmup' => fake()->boolean(20),
            'order' => fake()->numberBetween(1, 10),
        ];
    }
}
