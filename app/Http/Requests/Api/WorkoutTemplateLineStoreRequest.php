<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Http\FormRequest;

class WorkoutTemplateLineStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workoutTemplateId = $this->input('workout_template_id');

        // Let validation rules handle missing ID
        if (! $workoutTemplateId) {
            return true;
        }

        /** @var \App\Models\WorkoutTemplate|null $workoutTemplate */
        $workoutTemplate = WorkoutTemplate::find($workoutTemplateId);

        // Let validation rules handle non-existent ID
        if (! $workoutTemplate) {
            return true;
        }

        /** @var \App\Models\User $user */
        $user = $this->user();

        return $workoutTemplate->user_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_template_id' => [
                'required',
                'exists:workout_templates,id',
            ],
            'exercise_id' => [
                'required',
                'exists:exercises,id',
            ],
            'order' => 'nullable|integer',
        ];
    }
}
