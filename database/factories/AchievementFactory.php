<?php

namespace Database\Factories;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Achievement>
 */
class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'icon' => '🏆',
            'type' => fake()->randomElement(['workout_count', 'streak', 'volume', 'personal_record']),
            'threshold' => fake()->numberBetween(1, 100),
            'category' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
        ];
    }
}
