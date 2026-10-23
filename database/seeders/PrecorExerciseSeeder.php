<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

/**
 * Le catalogue commun des exercices des machines de la salle, que le bouton
 * « Installer Exercices par Défaut » du panneau installe.
 *
 * Il ne fait que créer ce qui manque : un exercice commun du même nom est
 * laissé tel quel, et rien n'est supprimé. Le bouton ne demande donc que la
 * capacité de créer un exercice (`ListExercises`) ; modifier ou supprimer un
 * exercice du catalogue passe par sa fiche, et par sa permission. Le
 * propriétaire n'est pas affecté en masse (`user_id` n'est pas dans le
 * `$fillable` d'`Exercise`) : la recherche se borne au catalogue commun, et
 * un exercice créé n'a pas de propriétaire.
 */
class PrecorExerciseSeeder extends Seeder
{
    public function run(): void
    {
        $exercises = [
            // Pectoraux
            ['name' => 'Chest Press', 'type' => 'strength', 'category' => 'Pectoraux'],
            ['name' => 'Incline Chest Press', 'type' => 'strength', 'category' => 'Pectoraux'],
            ['name' => 'Pec Fly', 'type' => 'strength', 'category' => 'Pectoraux'],

            // Épaules
            ['name' => 'Shoulder Press', 'type' => 'strength', 'category' => 'Épaules'],
            ['name' => 'Lateral Raise', 'type' => 'strength', 'category' => 'Épaules'],
            ['name' => 'Rear Delt Fly', 'type' => 'strength', 'category' => 'Épaules'],

            // Dos
            ['name' => 'Lat Pulldown', 'type' => 'strength', 'category' => 'Dos'],
            ['name' => 'Seated Row', 'type' => 'strength', 'category' => 'Dos'],
            ['name' => 'Back Extension', 'type' => 'strength', 'category' => 'Dos'],

            // Jambes
            ['name' => 'Leg Extension', 'type' => 'strength', 'category' => 'Jambes'],
            ['name' => 'Leg Curl', 'type' => 'strength', 'category' => 'Jambes'],
            ['name' => 'Leg Press', 'type' => 'strength', 'category' => 'Jambes'],
            ['name' => 'Calf Extension', 'type' => 'strength', 'category' => 'Jambes'],
            ['name' => 'Adductor', 'type' => 'strength', 'category' => 'Jambes'],
            ['name' => 'Abductor', 'type' => 'strength', 'category' => 'Jambes'],

            // Bras
            ['name' => 'Biceps Curl', 'type' => 'strength', 'category' => 'Bras'],
            ['name' => 'Triceps Extension', 'type' => 'strength', 'category' => 'Bras'],

            // Abdominaux
            ['name' => 'Abdominal Crunch', 'type' => 'strength', 'category' => 'Abdominaux'],
            ['name' => 'Rotary Torso', 'type' => 'strength', 'category' => 'Abdominaux'],

            // Cardio
            ['name' => 'Tapis de Course', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'Vélo Elliptique (EFX)', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'AMT', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'Vélo Droit', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'Vélo Assis', 'type' => 'cardio', 'category' => 'Cardio'],
        ];

        foreach ($exercises as $exercise) {
            Exercise::query()->whereNull('user_id')->firstOrCreate(['name' => $exercise['name']], $exercise);
        }
    }
}
