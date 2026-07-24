<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BodyPartMeasurement>
 */
class BodyPartMeasurementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'part' => $this->faker->randomElement(['Chest', 'Waist', 'Biceps']),
            'value' => $this->faker->randomFloat(2, 20, 150),
            'unit' => 'cm',
            'measured_at' => $this->faker->date(),
            'notes' => $this->faker->sentence(),
        ];
    }
}
