<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

final class CreateWorkoutFromTemplateAction
{
    /**
     * Create a new workout from an existing template.
     */
    public function execute(User $user, WorkoutTemplate $template): Workout
    {
        // Optimize: Eager load relationships to prevent N+1 queries during iteration
        $template->load(['workoutTemplateLines.workoutTemplateSets']);

        return DB::transaction(function () use ($user, $template): \App\Models\Workout {
            $workout = new Workout([
                'name' => $template->name,
                'started_at' => now(),
            ]);
            $workout->user_id = $user->id;
            $workout->save();

            $this->createLinesAndSets($workout, $template);

            return $workout;
        });
    }

    /**
     * Recopie chaque ligne du modèle et ses séries dans la séance.
     *
     * Les répétitions et le poids sont ramenés sous les plafonds d'une série
     * (`Set::ramenerALaBorne()`) : un modèle enregistré avant ces plafonds
     * peut les dépasser, et la page de séance recopie la dernière série d'un
     * exercice dans la suivante, que la requête refuserait alors. Le poids
     * d'un modèle se lit en chaîne (`decimal:2`) : il est converti avant.
     */
    private function createLinesAndSets(Workout $workout, WorkoutTemplate $template): void
    {
        $allSets = [];
        $now = now()->toDateTimeString();

        foreach ($template->workoutTemplateLines as $templateLine) {
            /** @var \App\Models\WorkoutLine $workoutLine */
            $workoutLine = $workout->workoutLines()->create([
                'exercise_id' => $templateLine->exercise_id,
                'order' => $templateLine->order,
            ]);

            foreach ($templateLine->workoutTemplateSets as $rang => $templateSet) {
                $allSets[] = [
                    'workout_line_id' => $workoutLine->id,
                    // `insert()` ne pose aucun defaut : sans ce rang, toutes
                    // les series de l'exercice partageraient un ordre nul et
                    // rien ne les departagerait.
                    'order' => $rang,
                    // `insert()` ne declenche aucun evenement : la copie du
                    // proprietaire doit etre posee ici, sans quoi ces series
                    // seraient invisibles a `GET /api/v1/sets`.
                    'user_id' => $workoutLine->user_id,
                    'reps' => Set::ramenerALaBorne('reps', is_numeric($templateSet->reps) ? (int) $templateSet->reps : null),
                    'weight' => Set::ramenerALaBorne('weight', is_numeric($templateSet->weight) ? (float) $templateSet->weight : null),
                    'is_warmup' => $templateSet->is_warmup,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

            }
        }

        if ($allSets !== []) {
            /*
             * `insert()` en masse pour eviter une requete par serie. Aucun
             * evenement `Set::saved` ne part, ce qui est desormais sans
             * consequence sur le volume : les series d'un modele arrivent non
             * validees, donc elles ne comptent pas encore.
             *
             * L'increment qui suivait cet appel creditait le volume complet du
             * modele au moment ou la seance s'ouvrait — le defaut de #1499. Il
             * est parti : `Workout::recalculerLeVolume()` s'en charge a la
             * premiere serie cochee, et sur les faits plutot que sur un total
             * calcule en PHP.
             */
            Set::insert($allSets);
        }
    }
}
