<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Injury;
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
        $status = $this->faker->randomElement(['active', 'recovering', 'healed']);
        $injuredAt = $this->faker->dateTimeBetween('-1 year', 'now');
        $healedAt = $status === 'healed' ? $this->faker->dateTimeBetween($injuredAt, 'now') : null;

        return [
            'user_id' => User::factory(),
            'body_part' => $this->faker->randomElement(['Knee', 'Shoulder', 'Back', 'Elbow', 'Ankle']),
            'description' => $this->faker->sentence(),
            'status' => $status,
            'injured_at' => $injuredAt,
            'healed_at' => $healedAt,
            'notes' => $this->faker->optional()->paragraph(),
        ];
    }
}
