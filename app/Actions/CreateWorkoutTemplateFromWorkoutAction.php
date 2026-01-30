<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

class CreateWorkoutTemplateFromWorkoutAction
{
    /**
     * Create a new workout template from an existing workout.
     */
    public function execute(User $user, Workout $workout): WorkoutTemplate
    {
        // Eager load relationships to prevent N+1 queries during iteration
        $workout->load(['workoutLines.sets']);

        return DB::transaction(function () use ($user, $workout): \App\Models\WorkoutTemplate {
            $template = new WorkoutTemplate([
                'name' => $workout->name.' (Modèle)',
                'description' => 'Créé à partir de la séance du '.($workout->created_at?->format('d/m/Y') ?? now()->format('d/m/Y')),
            ]);
            $template->user_id = $user->id;
            $template->save();

            $this->copyExercises($template, $workout);

            return $template;
        });
    }

    private function copyExercises(WorkoutTemplate $template, Workout $workout): void
    {
        foreach ($workout->workoutLines as $line) {
            /** @var \App\Models\WorkoutTemplateLine $templateLine */
            $templateLine = $template->workoutTemplateLines()->create([
                'exercise_id' => $line->exercise_id,
                'order' => $line->order,
            ]);

            foreach ($line->sets as $set) {
                $templateLine->workoutTemplateSets()->create([
                    'reps' => $set->reps,
                    'weight' => $set->weight,
                    'is_warmup' => $set->is_warmup,
                    'order' => $set->id, // Simple order for now
                ]);
            }
        }
    }
}
