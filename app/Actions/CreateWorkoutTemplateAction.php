<?php

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

class CreateWorkoutTemplateAction
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
        foreach ($exercises as $index => $ex) {
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
}
