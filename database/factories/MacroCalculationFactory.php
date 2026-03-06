<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MacroCalculation>
 */
class MacroCalculationFactory extends Factory
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
            'gender' => $this->faker->randomElement(['male', 'female']),
            'age' => $this->faker->numberBetween(18, 80),
            'height' => $this->faker->numberBetween(150, 200),
            'weight' => $this->faker->numberBetween(50, 100),
            'activity_level' => $this->faker->randomElement([1.2, 1.375, 1.55, 1.725, 1.9]),
            'goal' => $this->faker->randomElement(['cut', 'maintain', 'bulk']),
            'tdee' => $this->faker->numberBetween(2000, 3000),
            'target_calories' => $this->faker->numberBetween(1800, 3200),
            'protein' => $this->faker->numberBetween(100, 200),
            'fat' => $this->faker->numberBetween(50, 100),
            'carbs' => $this->faker->numberBetween(200, 400),
        ];
    }
}
