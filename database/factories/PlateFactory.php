<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plate>
 */
class PlateFactory extends Factory
{
    protected $model = Plate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'weight' => fake()->randomElement([1.25, 2.5, 5, 10, 15, 20, 25]),
            'quantity' => fake()->numberBetween(2, 8),
        ];
    }
}
