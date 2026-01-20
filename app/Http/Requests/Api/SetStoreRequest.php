<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'workout_line_id' => [
                'required',
                Rule::exists('workout_lines', 'id')->where(function ($query) use ($userId) {
                    // Check if the workout line belongs to a workout owned by the user
                    $query->whereIn('workout_id', function ($subQuery) use ($userId) {
                        $subQuery->select('id')
                            ->from('workouts')
                            ->where('user_id', $userId);
                    });
                }),
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
