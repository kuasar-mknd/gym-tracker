<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
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
            /*
             * Unique contre la base, pas seulement contre cette instance Faker.
             *
             * La contrainte est composite — `notification_preferences_user_id_type_unique`
             * porte sur (user_id, type) — et `unique()` ne memorise rien d'une
             * instance a l'autre. Deux preferences tirees pour le meme
             * utilisateur dans deux tests differents pouvaient donc entrer en
             * collision. Meme correction que sur UserFactory (#1425) et
             * AdminFactory (#1366).
             */
            'type' => Str::lower(Str::random(12)),
            'value' => $this->faker->numberBetween(1, 100),
            'is_enabled' => $this->faker->boolean,
            'is_push_enabled' => $this->faker->boolean,
        ];
    }
}
