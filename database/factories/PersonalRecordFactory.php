<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalRecord>
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
            'user_id' => User::factory(),
            'exercise_id' => Exercise::factory(),
            'workout_id' => Workout::factory(),
            // 'set_id' => \App\Models\Set::factory(), // Optional, might cause recursion or issue if Set factory doesn't exist yet. Nullable in DB?
            'type' => fake()->randomElement(['strength', 'cardio']),
            'value' => fake()->randomFloat(2, 5, 200),
            'achieved_at' => now(),
        ];
    }
}
