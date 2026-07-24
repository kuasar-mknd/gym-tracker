<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Facades\DB;

final class CreateWorkoutTemplateFromWorkoutAction
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
        $now = now();
        $linesData = [];

        $workoutLines = $workout->workoutLines->values();

        foreach ($workoutLines as $line) {
            $linesData[] = [
                'workout_template_id' => $template->id,
                'exercise_id' => $line->exercise_id,
                'order' => $line->order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($linesData === []) {
            return;
        }

        WorkoutTemplateLine::insert($linesData);

        $templateLines = $template->workoutTemplateLines()->orderBy('id')->get();

        $setsData = [];

        foreach ($workoutLines as $index => $line) {
            if (! isset($templateLines[$index])) {
                continue;
            }
            $templateLine = $templateLines[$index];

            foreach ($line->sets as $set) {
                $setsData[] = [
                    'workout_template_line_id' => $templateLine->id,
                    'reps' => $set->reps,
                    'weight' => $set->weight,
                    'is_warmup' => $set->is_warmup,
                    'order' => $set->id, // Simple order for now
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($setsData !== []) {
            // Chunking to avoid parameter limits in SQL (SQLite max is 999 typically)
            foreach (array_chunk($setsData, 100) as $chunk) {
                WorkoutTemplateSet::insert($chunk);
            }
        }
    }
}
