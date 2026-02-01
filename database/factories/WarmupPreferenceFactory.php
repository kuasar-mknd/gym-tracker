<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\WarmupPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WarmupPreference>
 */
class WarmupPreferenceFactory extends Factory
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
            'bar_weight' => $this->faker->randomElement([20.0, 15.0, 10.0]),
            'rounding_increment' => $this->faker->randomElement([2.5, 1.25, 5.0]),
            'steps' => [
                ['percent' => 50, 'reps' => 10, 'label' => 'Warmup 1'],
                ['percent' => 70, 'reps' => 5, 'label' => 'Warmup 2'],
                ['percent' => 90, 'reps' => 3, 'label' => 'Warmup 3'],
            ],
        ];
    }
}
