<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Injury>
 */
class InjuryFactory extends Factory
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
            'body_part' => $this->faker->word,
            'description' => $this->faker->sentence,
            'severity' => $this->faker->numberBetween(1, 10),
            'status' => $this->faker->randomElement(['active', 'recovering', 'healed']),
            'occurred_at' => $this->faker->date(),
            'recovered_at' => null,
            'notes' => $this->faker->paragraph,
        ];
    }
}
