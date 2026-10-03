<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PersonalRecordType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PersonalRecord>
 */
class PersonalRecordFactory extends Factory
{
    /**
     * Un record de poids maximal, sur un exercice et un compte qui lui sont
     * propres, sans série.
     *
     * Le type est fixe, et c'est un type que l'application tient. La fabrique
     * tirait au sort 'strength' ou 'cardio' : des types d'EXERCICE, que
     * l'application n'écrit pas comme records (#1811). Chaque test qui ne
     * précisait pas le type remplissait donc la base de valeurs héritées, au
     * hasard. Un autre type se demande explicitement, et deux records du même
     * compte sur le même exercice doivent préciser le leur : l'index unique
     * (user_id, exercise_id, type) refuse le second.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'exercise_id' => \App\Models\Exercise::factory(),
            'workout_id' => \App\Models\Workout::factory(),
            'type' => PersonalRecordType::MaxWeight,
            'value' => fake()->randomFloat(2, 5, 200),
            'achieved_at' => now(),
        ];
    }
}
