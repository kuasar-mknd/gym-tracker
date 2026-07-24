<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BodyMeasurement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BodyMeasurement>
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
            'user_id' => User::factory(),
            'weight' => $this->faker->randomFloat(2, 50, 150),
            'measured_at' => $this->faker->date(),
            'notes' => $this->faker->sentence(),
        ];
    }
}
