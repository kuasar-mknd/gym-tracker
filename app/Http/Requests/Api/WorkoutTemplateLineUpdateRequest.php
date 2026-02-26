<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\WorkoutTemplateLine;
use Illuminate\Foundation\Http\FormRequest;

class WorkoutTemplateLineUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workoutTemplateLine = $this->route('workout_template_line');

        if (! $workoutTemplateLine instanceof WorkoutTemplateLine) {
            $workoutTemplateLine = WorkoutTemplateLine::find($workoutTemplateLine);
        }

        if (! $workoutTemplateLine instanceof WorkoutTemplateLine) {
            return false;
        }

        /** @var \App\Models\User $user */
        $user = $this->user();

        return $workoutTemplateLine->workoutTemplate->user_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exercise_id' => [
                'sometimes',
                'exists:exercises,id',
            ],
            'order' => 'sometimes|integer',
        ];
    }
}
