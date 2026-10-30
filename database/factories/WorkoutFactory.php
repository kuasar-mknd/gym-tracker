<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workout>
 */
class WorkoutFactory extends Factory
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
            // What CreateWorkoutAction gives a real one: the date, on its own.
            'name' => $this->faker->date('d/m/Y'),
            'started_at' => now(),
        ];
    }

    /**
     * Une séance FAITE : terminée une heure après son début, avec un exercice
     * et une série cochée.
     *
     * Sans état, la fabrique rend ce que donne « Démarrer » : une séance en
     * cours, vide. Les succès de séances et les objectifs de fréquence ne
     * comptent que les séances faites (`User::seancesFaites()`, #2110) : un
     * test qui en a besoin le dit ici plutôt que de poser la fin et la série
     * à la main.
     *
     * La série est fixe et modeste, vingt kilos pour cinq répétitions : tirée
     * au hasard jusqu'à 200 kg comme celle de la fabrique des séries, elle
     * débloquerait au passage un succès de charge dans un test qui sème tout
     * le catalogue pour ne compter que des séances (`AchievementTest`).
     *
     * La fin se pose sur la séance faite, pas dans un état : la fabrique
     * applique les attributs donnés à `create()` ou `make()`, et les états
     * posés après celui-ci, après lui. Un état ne voyait donc que le début
     * par défaut, et une séance démarrée demain finissait aujourd'hui. Une fin
     * donnée reste la sienne (`FabriqueDesSeancesFaitesTest`).
     *
     * La série passe par les écouteurs des séries (records, valeurs
     * recommandées, volume, recalculs du compte). Un test qui ne lit pas leur
     * effet crée ses séances faites sous `Workout::withoutEvents()`
     * (`.ai/rules/tests.md`).
     */
    public function faite(): static
    {
        return $this
            ->afterMaking(function (\App\Models\Workout $seance): void {
                $seance->ended_at ??= $seance->started_at->copy()->addHour();
            })
            ->afterCreating(function (\App\Models\Workout $seance): void {
                \App\Models\Set::factory()->create([
                    'workout_line_id' => \App\Models\WorkoutLine::factory()->create(['workout_id' => $seance->id])->id,
                    'weight' => 20,
                    'reps' => 5,
                ]);
            });
    }
}
