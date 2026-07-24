<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

final class CreateWorkoutFromTemplateAction
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

            $this->createLinesAndSets($workout, $template, $user);

            return $workout;
        });
    }

    private function createLinesAndSets(Workout $workout, WorkoutTemplate $template, User $user): void
    {
        // Optimization: Prime the relationship to prevent N+1 queries in observers
        $workout->setRelation('user', $user);

        $allSets = [];
        $totalWorkoutVolume = 0.0;
        $now = now()->toDateTimeString();

        foreach ($template->workoutTemplateLines as $templateLine) {
            /** @var \App\Models\WorkoutLine $workoutLine */
            $workoutLine = $workout->workoutLines()->create([
                'exercise_id' => $templateLine->exercise_id,
                'order' => $templateLine->order,
            ]);
            $workoutLine->setRelation('workout', $workout);

            foreach ($templateLine->workoutTemplateSets as $templateSet) {
                $allSets[] = [
                    'workout_line_id' => $workoutLine->id,
                    'reps' => $templateSet->reps,
                    'weight' => $templateSet->weight,
                    'is_warmup' => $templateSet->is_warmup,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $volume = (float) ($templateSet->weight ?? 0) * (int) ($templateSet->reps ?? 0);
                $totalWorkoutVolume += $volume;
            }
        }

        if ($allSets !== []) {
            // Use insert() for bulk insertion to prevent N+1 queries during creation
            // We manually calculate and apply the volume since Set::saved events won't fire
            \App\Models\Set::insert($allSets);

            if ($totalWorkoutVolume > 0) {
                $user->increment('total_volume', $totalWorkoutVolume);
                $workout->increment('workout_volume', $totalWorkoutVolume);
            }
        }
    }
}
