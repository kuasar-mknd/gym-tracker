<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WilksScore>
 */
class WilksScoreFactory extends Factory
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
            'body_weight' => $this->faker->numberBetween(50, 120),
            'lifted_weight' => $this->faker->numberBetween(100, 300),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'unit' => 'kg',
            'score' => $this->faker->randomFloat(2, 200, 500),
        ];
    }
}
