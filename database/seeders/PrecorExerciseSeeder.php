<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

class PrecorExerciseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Nettoyage des anciens exercices avec le préfixe "Precor"
        Exercise::where('name', 'like', 'Precor %')->whereNull('user_id')->delete();

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
            ['name' => 'Abdominal Crunch', 'type' => 'strength', 'category' => 'Abdos'],
            ['name' => 'Rotary Torso', 'type' => 'strength', 'category' => 'Abdos'],

            // Cardio
            ['name' => 'Tapis de Course', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'Vélo Elliptique (EFX)', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'AMT', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'Vélo Droit', 'type' => 'cardio', 'category' => 'Cardio'],
            ['name' => 'Vélo Assis', 'type' => 'cardio', 'category' => 'Cardio'],
        ];

        foreach ($exercises as $exercise) {
            Exercise::updateOrCreate(
                ['name' => $exercise['name'], 'user_id' => null],
                $exercise
            );
        }
    }
}
