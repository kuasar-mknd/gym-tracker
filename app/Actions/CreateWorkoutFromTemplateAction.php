<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Action class responsible for creating a new workout instance
 * based on an existing workout template.
 *
 * This includes copying all template lines and sets into
 * actual workout lines and sets for the user to perform.
 */
final class CreateWorkoutFromTemplateAction
{
    /**
     * Create a new workout from an existing template.
     *
     * @param User $user The user performing the workout.
     * @param WorkoutTemplate $template The template to base the workout on.
     * @return Workout The newly created workout instance.
     * @throws \Throwable If the database transaction fails.
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

    /**
     * Creates the corresponding workout lines and sets from the template.
     *
     * Iterates over the template's lines and sets, creates the new workout lines,
     * and performs a bulk insertion of all sets to optimize database queries.
     * It also calculates and updates the total workout volume.
     *
     * @param Workout $workout The newly created workout.
     * @param WorkoutTemplate $template The source workout template.
     * @param User $user The user performing the workout.
     * @return void
     */
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
