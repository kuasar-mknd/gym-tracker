<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

class CreateWorkoutFromTemplateAction
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

    private function createLinesAndSets(Workout $workout, WorkoutTemplate $template): void
    {
        foreach ($template->workoutTemplateLines as $templateLine) {
            /** @var \App\Models\WorkoutLine $workoutLine */
            $workoutLine = $workout->workoutLines()->create([
                'exercise_id' => $templateLine->exercise_id,
                'order' => $templateLine->order,
            ]);

            foreach ($templateLine->workoutTemplateSets as $templateSet) {
                $workoutLine->sets()->create([
                    'reps' => $templateSet->reps,
                    'weight' => $templateSet->weight,
                    'is_warmup' => $templateSet->is_warmup,
                ]);
            }
        }
    }
}
