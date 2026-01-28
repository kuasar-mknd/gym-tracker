<?php

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplement>
 */
class SupplementFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
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
            'user_id' => User::factory(),
            'name' => $this->faker->word(),
            'brand' => $this->faker->company(),
            'dosage' => $this->faker->randomDigitNotNull() . 'g',
            'servings_remaining' => $this->faker->numberBetween(1, 100),
            'low_stock_threshold' => $this->faker->numberBetween(1, 10),
        ];
    }
}
