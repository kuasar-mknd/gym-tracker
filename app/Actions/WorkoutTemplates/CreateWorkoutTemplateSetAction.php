<?php

declare(strict_types=1);

namespace App\Actions\WorkoutTemplates;

use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;

class CreateWorkoutTemplateSetAction
{
    /**
     * Create a new workout template set for a workout template line.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(WorkoutTemplateLine $workoutTemplateLine, array $data): WorkoutTemplateSet
    {
        /** @var int|null $maxOrder */
        $maxOrder = $workoutTemplateLine->workoutTemplateSets()->max('order');
        $order = $data['order'] ?? ($maxOrder === null ? 0 : $maxOrder + 1);

        /** @var \App\Models\WorkoutTemplateSet */
        return $workoutTemplateLine->workoutTemplateSets()->create(array_merge(
            collect($data)->except('workout_template_line_id')->toArray(),
            ['order' => $order]
        ));
    }
}
