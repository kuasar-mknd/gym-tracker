<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IntervalTimer>
 */
class IntervalTimerFactory extends Factory
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
            'name' => $this->faker->word(),
            'work_seconds' => $this->faker->numberBetween(10, 60),
            'rest_seconds' => $this->faker->numberBetween(5, 30),
            'rounds' => $this->faker->numberBetween(1, 10),
            'warmup_seconds' => $this->faker->numberBetween(0, 10),
        ];
    }
}
