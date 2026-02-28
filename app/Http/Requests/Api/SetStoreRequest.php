<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\WorkoutLine;
use Illuminate\Foundation\Http\FormRequest;

class SetStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workoutLineId = $this->input('workout_line_id');

        // Let validation rules handle missing ID
        if (! $workoutLineId) {
            return true;
        }

        /** @var \App\Models\WorkoutLine|null $workoutLine */
        $workoutLine = WorkoutLine::with('workout')->find($workoutLineId);

        // Let validation rules handle non-existent ID
        /** @phpstan-ignore-next-line */
        if (! $workoutLine || ! $workoutLine->workout) {
            return true;
        }

        /** @var \App\Models\User|null $user */
        $user = $this->user();

        if (! $user) {
            return false;
        }

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
            'workout_line_id' => [
                'required',
                'exists:workout_lines,id',
            ],
            'weight' => 'nullable|numeric|min:0',
            'reps' => 'nullable|integer|min:0',
            'duration_seconds' => 'nullable|integer|min:0',
            'distance_km' => 'nullable|numeric|min:0',
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ];
    }
}
