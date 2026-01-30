<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Equipment>
 */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->word,
            'type' => (string) $this->faker->randomElement(['shoes', 'belt', 'sleeves', 'straps', 'other']),
            'brand' => $this->faker->company,
            'model' => $this->faker->word,
            'purchased_at' => $this->faker->date(),
            'is_active' => true,
            'notes' => $this->faker->sentence,
        ];
    }
}
