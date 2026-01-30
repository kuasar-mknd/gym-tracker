<?php

namespace Database\Factories;

use App\Models\User;
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
            'user_id' => User::factory(),
            'body_part' => $this->faker->word,
            'description' => $this->faker->sentence,
            'status' => 'active',
            'pain_level' => $this->faker->numberBetween(1, 10),
            'occurred_at' => $this->faker->date(),
            'healed_at' => null,
            'notes' => $this->faker->paragraph,
        ];
    }
}
