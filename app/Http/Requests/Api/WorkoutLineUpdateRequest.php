<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\WorkoutLine;
use Illuminate\Foundation\Http\FormRequest;

class WorkoutLineUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workoutLine = $this->route('workout_line');

        if (! $workoutLine instanceof WorkoutLine) {
            $workoutLine = WorkoutLine::find($this->route('workout_line'));
        }

        if (! $workoutLine instanceof WorkoutLine) {
            return false;
        }

        /** @var \App\Models\User $user */
        $user = $this->user();

        return $workoutLine->workout->user_id === $user->id;
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
            'notes' => 'nullable|string',
        ];
    }
}
