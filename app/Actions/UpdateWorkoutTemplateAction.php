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

        if ($exercises === []) {
            return;
        }

        $now = now()->toDateTimeString();

        \App\Models\WorkoutTemplateLine::insert(array_map(
            fn (array $ex, int $index): array => [
                'workout_template_id' => $template->id,
                'exercise_id' => $ex['id'],
                'order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $exercises,
            array_keys($exercises),
        ));

        // Fetch the generated lines to get their IDs
        $lines = \App\Models\WorkoutTemplateLine::where('workout_template_id', $template->id)
            ->orderBy('order')
            ->get();

        $donneesDesSeries = [];
        foreach ($exercises as $index => $ex) {
            if (isset($ex['sets'], $lines[$index])) {
                $this->appendSetsData($donneesDesSeries, $ex['sets'], $lines[$index]->id, $now);
            }
        }

        $this->insertSetsData($donneesDesSeries);
    }

    /** Les series partent avec leurs lignes, par la cascade de la base. */
    private function deleteExistingLines(WorkoutTemplate $template): void
    {
        $template->workoutTemplateLines()->delete();
    }
}
