<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PersonalRecord>
 */
class PersonalRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'exercise_id' => \App\Models\Exercise::factory(),
            'workout_id' => \App\Models\Workout::factory(),
            // 'set_id' => \App\Models\Set::factory(), // Optional, might cause recursion or issue if Set factory doesn't exist yet. Nullable in DB?
            'type' => fake()->randomElement(['strength', 'cardio']),
            'value' => fake()->randomFloat(2, 5, 200),
            'achieved_at' => now(),
        ];
    }
}
