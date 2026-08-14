<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Achievement>
 */
class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    public function definition(): array
    {
        return [
            /*
             * Unique contre la base, pas seulement contre cette instance Faker.
             *
             * `fake()->unique()` ne memorise que ce qu'il a tire dans une
             * instance, reconstruite a chaque test, alors que
             * `achievements_slug_unique` vit dans la base sur toute
             * l'execution. Le suffixe aleatoire supprime le vivier au lieu de
             * parier dessus. Meme correction que sur UserFactory (#1425) et
             * AdminFactory (#1366) — mesure a l'appui : 19 campagnes sur 20
             * collisionnent a 2000 tirages.
             */
            'slug' => 'achievement-'.Str::lower(Str::random(12)),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'icon' => '🏆',
            'type' => fake()->randomElement(['workout_count', 'streak', 'volume', 'personal_record']),
            'threshold' => fake()->numberBetween(1, 100),
            'category' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
        ];
    }
}
