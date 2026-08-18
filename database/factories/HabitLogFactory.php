<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Habit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HabitLog>
 */
class HabitLogFactory extends Factory
{
    /**
     * Le rang de la prochaine date distribuee. Voir la note sur `date`.
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
            'habit_id' => Habit::factory(),
            /*
             * Une date distincte a chaque appel, comme pour DailyJournalFactory.
             *
             * `habit_logs` porte `UNIQUE (habit_id, date)` et cette fabrique
             * tirait au hasard dans un vivier d'environ 20 000 jours. Trois
             * suivis pour la meme habitude — ce que fait HabitLogTest — suffisent
             * a faire echouer une execution de temps en temps, dans un test sans
             * rapport avec celui qu'on lit.
             *
             * Trouve en generalisant le garde ne apres #1475 : le meme defaut,
             * la meme forme, une table plus loin.
             */
            'date' => CarbonImmutable::parse('2015-01-01')->addDays(self::$prochainJour++)->toDateString(),
            'notes' => $this->faker->sentence(),
        ];
    }
}
