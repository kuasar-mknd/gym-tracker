<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Traits\HandlesWorkoutTemplateSets;
use Illuminate\Support\Facades\DB;

final class CreateWorkoutTemplateAction
{
    use HandlesWorkoutTemplateSets;

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
        if ($exercises === []) {
            return;
        }

        $linesData = [];
        $setsData = [];
        $now = now()->toDateTimeString();

        foreach ($exercises as $index => $ex) {
            $linesData[] = [
                'workout_template_id' => $template->id,
                'exercise_id' => $ex['id'],
                'order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        \App\Models\WorkoutTemplateLine::insert($linesData);

        $lines = $template->workoutTemplateLines()->get()->keyBy('order');

        foreach ($exercises as $index => $ex) {
            if (isset($ex['sets'])) {
                $line = $lines->get($index);
                if ($line) {
                    $this->appendSetsData($setsData, $ex['sets'], $line->id, $now);
                }
            }
        }

        $this->insertSetsData($setsData);
    }
}
