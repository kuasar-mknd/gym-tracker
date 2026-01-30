<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplement>
 */
class SupplementFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Supplement>
     */
    protected $model = Supplement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'brand' => $this->faker->company(),
            'dosage' => $this->faker->numberBetween(1, 100) . 'mg',
            'servings_remaining' => $this->faker->numberBetween(10, 100),
            'low_stock_threshold' => $this->faker->numberBetween(1, 10),
            'user_id' => \App\Models\User::factory(),
        ];
    }
}
