<?php

declare(strict_types=1);

namespace App\Actions\WorkoutTemplates;

use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Arr;

class CreateWorkoutTemplateLineAction
{
    use AuthorizesRequests;

    /**
     * @param  array{workout_template_id: int, exercise_id: int, order?: int|null}  $data
     */
    public function execute(array $data): WorkoutTemplateLine
    {
        /** @var \App\Models\WorkoutTemplate $workoutTemplate */
        $workoutTemplate = WorkoutTemplate::findOrFail($data['workout_template_id']);

        $this->authorize('create', [WorkoutTemplateLine::class, $workoutTemplate]);

        /** @var int|null $maxOrder */
        $maxOrder = $workoutTemplate->workoutTemplateLines()->max('order');
        $order = $data['order'] ?? ($maxOrder === null ? 0 : $maxOrder + 1);

        /**
         * La forme est declaree parce que rien ne la porte jusqu'ici.
         *
         * `collect($data)->except(...)->toArray()` — ce qui etait ecrit — rend
         * un `array<int|string, mixed>` : la traversee par une collection perd
         * la garantie que les cles sont textuelles, que la forme du parametre
         * donne pourtant. `Arr::except` ne la perd pas moins : son stub Laravel
         * rend un `array` nu. `create()` et `make()` exigent
         * `array<string, mixed>`, d'ou une entree de baseline par appel — cinq
         * en tout.
         *
         * L'annotation dit ce que la signature garantit deja, elle n'affirme
         * rien de neuf.
         *
         * @var array<string, mixed> $attributs
         */
        $attributs = array_merge(Arr::except($data, ['workout_template_id']), ['order' => $order]);

        /** @var \App\Models\WorkoutTemplateLine */
        return $workoutTemplate->workoutTemplateLines()->create($attributs);
    }
}
