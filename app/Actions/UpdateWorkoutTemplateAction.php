<?php

namespace App\Actions;

use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

class UpdateWorkoutTemplateAction
{
    /**
     * Update a workout template with exercises and sets.
     */
    public function execute(WorkoutTemplate $template, array $data): WorkoutTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $template->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? $template->description,
            ]);

            if (isset($data['exercises'])) {
                // Delete existing lines and sets
                foreach ($template->workoutTemplateLines()->get() as $line) {
                    $line->workoutTemplateSets()->delete();
                    $line->delete();
                }

                foreach ($data['exercises'] as $index => $ex) {
                    $line = $template->workoutTemplateLines()->create([
                        'exercise_id' => $ex['id'],
                        'order' => $index,
                    ]);

                    if (isset($ex['sets'])) {
                        foreach ($ex['sets'] as $setIndex => $set) {
                            $line->workoutTemplateSets()->create([
                                'reps' => $set['reps'] ?? null,
                                'weight' => $set['weight'] ?? null,
                                'is_warmup' => $set['is_warmup'] ?? false,
                                'order' => $setIndex,
                            ]);
                        }
                    }
                }
            }

            // Reload relations
            $template->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']);

            return $template;
        });
    }
}
