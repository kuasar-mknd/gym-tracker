<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SleepLog>
 */
class SleepLogFactory extends Factory
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
            'duration_minutes' => $this->faker->numberBetween(300, 600), // 5-10 hours
            'quality' => $this->faker->numberBetween(1, 5),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
