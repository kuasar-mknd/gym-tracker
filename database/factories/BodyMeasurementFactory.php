<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BodyMeasurement>
 */
class BodyMeasurementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'weight' => $this->faker->randomFloat(2, 50, 150),
            'measured_at' => $this->faker->date(),
            'notes' => $this->faker->sentence(),
        ];
    }
}
