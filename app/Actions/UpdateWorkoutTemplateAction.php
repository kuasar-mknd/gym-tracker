<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

final class UpdateWorkoutTemplateAction
{
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
        // Delete existing lines and sets using optimized queries
        $lineIds = $template->workoutTemplateLines()->pluck('id');
        if ($lineIds->isNotEmpty()) {
            \App\Models\WorkoutTemplateSet::whereIn('workout_template_line_id', $lineIds)->delete();
            $template->workoutTemplateLines()->delete();
        }

        $setsData = [];
        $now = now()->toDateTimeString();

        foreach ($exercises as $index => $ex) {
            $line = $template->workoutTemplateLines()->create([
                'exercise_id' => $ex['id'],
                'order' => $index,
            ]);

            if (isset($ex['sets'])) {
                foreach ($ex['sets'] as $setIndex => $set) {
                    $setsData[] = [
                        'workout_template_line_id' => $line->id,
                        'reps' => $set['reps'] ?? null,
                        'weight' => $set['weight'] ?? null,
                        'is_warmup' => $set['is_warmup'] ?? false,
                        'order' => $setIndex,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($setsData !== []) {
            // Chunking to avoid parameter limits in SQL (SQLite max is 999 typically)
            foreach (array_chunk($setsData, 100) as $chunk) {
                \App\Models\WorkoutTemplateSet::insert($chunk);
            }
        }
    }
}
