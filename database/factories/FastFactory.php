<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Fast>
 */
class FastFactory extends Factory
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
            'start_time' => $this->faker->dateTimeBetween('-1 day', 'now'),
            'target_duration_minutes' => 960, // 16 hours
            'type' => '16:8',
            'status' => 'active',
        ];
    }
}
