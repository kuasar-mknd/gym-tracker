<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaterLog>
 */
class WaterLogFactory extends Factory
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
            'amount' => $this->faker->numberBetween(100, 1000), // ml
            'consumed_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
