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
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Equipment>
     */
    protected $model = Equipment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $suffix */
        $suffix = $this->faker->randomElement(['Shoes', 'Belt', 'Sleeves']);

        /** @var string $type */
        $type = $this->faker->randomElement(['shoes', 'belt', 'sleeves', 'wraps', 'other']);

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->word . ' ' . $suffix,
            'type' => $type,
            'brand' => $this->faker->company,
            'model' => $this->faker->word,
            'purchased_at' => $this->faker->date(),
            'is_active' => true,
            'notes' => $this->faker->sentence,
        ];
    }
}
