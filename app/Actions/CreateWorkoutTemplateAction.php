<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Facades\DB;

final class CreateWorkoutTemplateAction
{
    /**
     * Create a new workout template with exercises and sets.
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
    public function execute(User $user, array $data): WorkoutTemplate
    {
        return DB::transaction(function () use ($user, $data): \App\Models\WorkoutTemplate {
            $template = new WorkoutTemplate([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $template->user_id = $user->id;
            $template->save();

            if (isset($data['exercises'])) {
                $this->addExercises($template, $data['exercises']);
            }

            return $template;
        });
    }

    /** @param array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}> $exercises */
    private function addExercises(WorkoutTemplate $template, array $exercises): void
    {
        $now = now();
        $linesData = [];
        foreach ($exercises as $index => $ex) {
            $linesData[] = [
                'workout_template_id' => $template->id,
                'exercise_id' => $ex['id'],
                'order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($linesData)) {
            return;
        }

        WorkoutTemplateLine::insert($linesData);

        // Fetch the inserted lines to get their IDs, keyed by their order to match back to exercises
        $linesByOrder = $template->workoutTemplateLines()
            ->whereIn('order', array_column($linesData, 'order'))
            ->get()
            ->keyBy('order');

        $setsData = [];
        foreach ($exercises as $index => $ex) {
            $line = $linesByOrder->get($index);

            if ($line && isset($ex['sets'])) {
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

        if (! empty($setsData)) {
            WorkoutTemplateSet::insert($setsData);
        }
    }
}
