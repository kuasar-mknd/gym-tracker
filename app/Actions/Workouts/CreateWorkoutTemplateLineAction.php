<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;

final class CreateWorkoutTemplateLineAction
{
    /**
     * Create a new workout template line.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(WorkoutTemplate $workoutTemplate, array $data): WorkoutTemplateLine
    {
        /** @var int|null $maxOrder */
        $maxOrder = $workoutTemplate->workoutTemplateLines()->max('order');
        $order = $data['order'] ?? ($maxOrder === null ? 0 : $maxOrder + 1);

        /** @var \App\Models\WorkoutTemplateLine $workoutTemplateLine */
        $workoutTemplateLine = $workoutTemplate->workoutTemplateLines()->create(array_merge(
            collect($data)->except('workout_template_id')->toArray(),
            ['order' => $order]
        ));

        return $workoutTemplateLine;
    }
}
