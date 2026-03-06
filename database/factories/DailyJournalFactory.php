<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailyJournal>
 */
class DailyJournalFactory extends Factory
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
            'date' => $this->faker->date(),
            'content' => $this->faker->paragraph(),
            'mood_score' => $this->faker->numberBetween(1, 5),
            'sleep_quality' => $this->faker->numberBetween(1, 5),
            'stress_level' => $this->faker->numberBetween(1, 10),
            'energy_level' => $this->faker->numberBetween(1, 10),
            'motivation_level' => $this->faker->numberBetween(1, 10),
            'nutrition_score' => $this->faker->numberBetween(1, 5),
            'training_intensity' => $this->faker->numberBetween(1, 10),
        ];
    }
}
