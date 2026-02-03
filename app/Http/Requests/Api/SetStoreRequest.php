<?php

declare(strict_types=1);

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
        return [
            'workout_line_id' => [
                'required',
                Rule::exists('workout_lines', 'id')->where(function ($query) {
                    /** @var \App\Models\User $user */
                    $user = $this->user();

                    $query->whereIn('workout_id', function ($q) use ($user) {
                        $q->select('id')->from('workouts')->where('user_id', $user->id);
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
