<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\WorkoutTemplate;
use App\Traits\HandlesWorkoutTemplateSets;
use Illuminate\Support\Facades\DB;

final class UpdateWorkoutTemplateAction
{
    use HandlesWorkoutTemplateSets;

    /**
     * Update a workout template with exercises and sets.
     *
     * @param array{
     *     name: string,
     *     description?: string|null,
     *     exercises?: array<int, array{
     *         id: int,
     *         sets?: array<int, array{
     *             reps?: int|null,
     *             weight?: float|null,
     *             is_warmup?: bool
     *         }>
     *     }>
     * } $data
     */
    public function execute(WorkoutTemplate $template, array $data): WorkoutTemplate
    {
        return DB::transaction(function () use ($template, $data): \App\Models\WorkoutTemplate {
            $template->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? $template->description,
            ]);

            if (isset($data['exercises'])) {
                $this->updateExercises($template, $data['exercises']);
            }

            // Reload relations
            $template->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']);

            return $template;
        });
    }

    /** @param array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}> $exercises */
    private function updateExercises(WorkoutTemplate $template, array $exercises): void
    {
        $this->deleteExistingLines($template);

        $setsData = [];
        $now = now()->toDateTimeString();

        foreach ($exercises as $index => $ex) {
            $line = $template->workoutTemplateLines()->create([
                'exercise_id' => $ex['id'],
                'order' => $index,
            ]);

            if (isset($ex['sets'])) {
                $this->appendSetsData($setsData, $ex['sets'], $line->id, $now);
            }
        }

        $this->insertSetsData($setsData);
    }

    private function deleteExistingLines(WorkoutTemplate $template): void
    {
        $lineIds = $template->workoutTemplateLines()->pluck('id');
        if ($lineIds->isNotEmpty()) {
            \App\Models\WorkoutTemplateSet::whereIn('workout_template_line_id', $lineIds)->delete();
            $template->workoutTemplateLines()->delete();
        }
    }
}
