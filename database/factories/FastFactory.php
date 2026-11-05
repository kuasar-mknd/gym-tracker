<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Fast>
 */
class FastFactory extends Factory
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
            'start_time' => $this->faker->dateTimeBetween('-1 day', 'now'),
            'target_duration_minutes' => 960, // 16 hours
            'type' => '16:8',
            'status' => 'active',
        ];
    }

    /**
     * Un jeûne écrit avant #2159, sans le décalage de son début et de sa fin :
     * il se lit comme le cast `datetime` le lisait, une heure de l'heure que le
     * passage à l'heure d'hiver répète dans sa seconde occurrence.
     *
     * Le cast (`InstantAvecSonDecalage`) écrit le décalage avec l'instant :
     * l'état l'efface ensuite, à la fabrication.
     */
    public function sansDecalage(): static
    {
        return $this->afterMaking(function (\App\Models\Fast $jeune): void {
            $jeune->start_time_offset = null;
            $jeune->end_time_offset = null;
        });
    }
}
