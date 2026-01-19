<?php

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
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
            'type' => $this->faker->unique()->word,
            'is_enabled' => $this->faker->boolean,
            'is_push_enabled' => $this->faker->boolean,
            'value' => $this->faker->numberBetween(1, 100),
        ];
    }
}
