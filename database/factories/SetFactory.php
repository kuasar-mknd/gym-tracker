<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Set>
 */
class SetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Une serie par defaut est une serie FAITE.
     *
     * `is_completed` n'etait pas renseigne, donc la fabrique retombait sur le
     * defaut de la base — non validee. Tant que le volume additionnait toutes
     * les series cela ne se voyait pas ; depuis qu'il ne compte que le travail
     * accompli (#1499), une fabrique muette produisait de l'entrainement qui
     * n'a pas eu lieu, et dix-neuf tests affirmaient un volume a partir de
     * series que personne n'avait cochees.
     *
     * Le dire ici plutot que dans chaque test : c'est ce que « une serie »
     * signifie quand on n'en precise pas davantage. L'etat inverse s'ecrit,
     * lui, explicitement — voir `naPasEteFaite()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workout_line_id' => \App\Models\WorkoutLine::factory(),
            'weight' => $this->faker->randomFloat(1, 0, 200),
            'reps' => $this->faker->numberBetween(1, 20),
            'is_warmup' => false,
            'is_completed' => true,
        ];
    }

    /**
     * Une serie inscrite mais pas encore realisee : celle qu'un modele
     * pre-remplit, ou celle qu'on vient d'ajouter et qu'on n'a pas cochee.
     */
    public function naPasEteFaite(): static
    {
        return $this->state(fn (array $attributes): array => ['is_completed' => false]);
    }
}
