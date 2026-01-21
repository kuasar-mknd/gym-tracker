<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoalUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('goal')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'in:weight,frequency,volume,measurement'],
            'target_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'current_value' => ['sometimes', 'numeric'],
            'start_value' => ['sometimes', 'numeric'],
            'exercise_id' => [
                'sometimes',
                'nullable',
                Rule::exists('exercises', 'id')->where(function ($query): void {
                    $query->where(function ($q): void {
                        $q->whereNull('user_id')->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
            'measurement_type' => ['sometimes', 'nullable', 'string'],
            'deadline' => ['nullable', 'date'], // Removed after:today to allow editing old goals without validation error if deadline passed
            'completed_at' => ['nullable', 'date'],
        ];
    }
}
