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
        $maxOrder = $workout->workoutLines()->max('order');
        $order = $data['order'] ?? (is_null($maxOrder) ? 0 : (int) $maxOrder + 1); // @phpstan-ignore cast.int

        /** @var WorkoutLine $workoutLine */
        return $workout->workoutLines()->create(array_merge(
            collect($data)->except('workout_id')->toArray(),
            ['order' => $order]
        ));
    }
}
