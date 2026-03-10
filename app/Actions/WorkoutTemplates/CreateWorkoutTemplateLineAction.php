<?php

declare(strict_types=1);

namespace App\Actions\WorkoutTemplates;

use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class CreateWorkoutTemplateLineAction
{
    use AuthorizesRequests;

    /**
     * @param array{workout_template_id: int, exercise_id: int, order?: int|null} $data
     */
    public function execute(array $data): WorkoutTemplateLine
    {
        /** @var \App\Models\WorkoutTemplate $workoutTemplate */
        $workoutTemplate = WorkoutTemplate::findOrFail($data['workout_template_id']);

        $this->authorize('create', [WorkoutTemplateLine::class, $workoutTemplate]);

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
