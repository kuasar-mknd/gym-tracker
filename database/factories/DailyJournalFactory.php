<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailyJournal>
 */
class DailyJournalFactory extends Factory
{
    /**
     * Le rang de la prochaine date distribuee.
     *
     * `daily_journals` porte une contrainte `UNIQUE (user_id, date)`, et
     * `faker->date()` tire dans un vivier d'environ 20 000 jours. Trois journaux
     * pour le meme utilisateur suffisaient donc a faire echouer une execution de
     * temps en temps sur un `UniqueConstraintViolationException` — dans un test
     * qui n'avait rien a voir avec celui qu'on lisait, et sans jamais tomber deux
     * fois au meme endroit.
     *
     * Meme lecon que pour `fake()->unique()` (voir FactoryUniquenessTest) : on
     * supprime le vivier au lieu de parier dessus. Le compteur est statique donc
     * propre a chaque processus, ce qui suffit — la base de test l'est aussi.
     */
    private static int $prochainJour = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            /*
             * L'ancre est volontairement loin des dates que les tests posent a
             * la main — aujourd'hui, le mois prochain, 2023-01-01 : il faudrait
             * plusieurs milliers de journaux dans un meme processus pour
             * l'atteindre, alors que la suite entiere en cree quelques dizaines.
             */
            'date' => CarbonImmutable::parse('2015-01-01')->addDays(self::$prochainJour++)->toDateString(),
            'content' => $this->faker->paragraph(),
            'mood_score' => $this->faker->numberBetween(1, 5),
            'sleep_quality' => $this->faker->numberBetween(1, 5),
            'stress_level' => $this->faker->numberBetween(1, 10),
            'energy_level' => $this->faker->numberBetween(1, 10),
            'motivation_level' => $this->faker->numberBetween(1, 10),
            'nutrition_score' => $this->faker->numberBetween(1, 5),
            'training_intensity' => $this->faker->numberBetween(1, 10),
        ];
    }
}
