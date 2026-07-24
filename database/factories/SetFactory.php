<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Set;
use App\Models\WorkoutLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Set>
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
            'workout_line_id' => WorkoutLine::factory(),
            'weight' => $this->faker->randomFloat(1, 0, 200),
            'reps' => $this->faker->numberBetween(1, 20),
            'is_warmup' => false,
        ];
    }
}
