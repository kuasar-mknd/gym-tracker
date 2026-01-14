<?php

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

class CreateWorkoutTemplateAction
{
    /**
     * Create a workout template with exercises and sets.
     */
    public function execute(User $user, array $data): WorkoutTemplate
    {
        return DB::transaction(function () use ($user, $data) {
            $template = WorkoutTemplate::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            if (isset($data['exercises'])) {
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

            return $template;
        });
    }
}
