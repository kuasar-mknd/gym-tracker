<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Models\WorkoutLine;

class CreateWorkoutLineAction
{
    /**
     * Create a new workout line for a workout.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Workout $workout, array $data): WorkoutLine
    {
        // @phpstan-ignore-next-line
        $order = $data['order'] ?? (is_null($workout->workoutLines()->max('order')) ? 0 : $workout->workoutLines()->max('order') + 1);

        /** @var WorkoutLine $workoutLine */
        $workoutLine = $workout->workoutLines()->create(array_merge(
            collect($data)->except('workout_id')->toArray(),
            ['order' => $order]
        ));

        return $workoutLine;
    }
}
