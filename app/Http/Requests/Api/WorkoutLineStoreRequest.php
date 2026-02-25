<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Workout;
use Illuminate\Foundation\Http\FormRequest;

class WorkoutLineStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workoutId = $this->input('workout_id');

        // Let validation rules handle missing ID
        if (! $workoutId) {
            return true;
        }

        /** @var \App\Models\Workout|null $workout */
        $workout = Workout::find($workoutId);

        // Let validation rules handle non-existent ID
        if (! $workout) {
            return true;
        }

        /** @var \App\Models\User $user */
        $user = $this->user();

        return $workout->user_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_id' => [
                'required',
                'exists:workouts,id',
            ],
            'exercise_id' => [
                'required',
                'exists:exercises,id',
            ],
            'order' => 'nullable|integer',
            'notes' => 'nullable|string',
        ];
    }
}
