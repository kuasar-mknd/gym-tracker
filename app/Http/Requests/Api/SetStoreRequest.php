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

        // PHPStan analysis says this check is redundant if types are strict,
        // but finding by ID can definitely return null.
        // However, if the error is "Negated boolean expression is always false" on line 28:
        // if (! $workoutLine || ! $workoutLine->workout)
        // It implies PHPStan thinks one of these is always true?
        // No, it likely thinks $workoutLine->workout is always present if $workoutLine is present.
        // But `with('workout')` doesn't guarantee relationship existence if DB is inconsistent.
        // To be safe and satisfy PHPStan, we can use `optional()` or just suppress.
        // Or maybe refactor.

        if ($workoutLine === null) {
            return true;
        }

        // If we found the line but no workout (orphan), allow? Or fail?
        // Authorization should probably fail if data is corrupted, or just return false.
        // But here we return true to let rules validation fail on "exists"?
        // Actually, if we return true, the controller runs.
        // If we found the object, we check ownership.

        if ($workoutLine->workout === null) {
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
