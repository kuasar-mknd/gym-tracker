<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkoutLine>
 */
class WorkoutLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workout_id' => \App\Models\Workout::factory(),
            'exercise_id' => \App\Models\Exercise::factory(),
            'order' => 0,
        ];
    }
}
