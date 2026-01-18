<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WarmupPreference>
 */
class WarmupPreferenceFactory extends Factory
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
            'name' => $this->faker->words(3, true),
            'sets_config' => [
                ['type' => 'bar', 'reps' => 10, 'value' => null],
                ['type' => 'percentage', 'reps' => 5, 'value' => 0.5],
            ],
        ];
    }
}
