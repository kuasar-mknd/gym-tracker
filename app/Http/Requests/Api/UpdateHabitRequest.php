<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHabitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var \App\Models\Habit $habit */
        $habit = $this->route('habit');

        return $this->user()?->getAuthIdentifier() === $habit->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color' => 'nullable|string',
            'icon' => 'nullable|string',
            'goal_times_per_week' => 'sometimes|integer|min:1|max:7',
            'archived' => 'boolean',
        ];
    }
}
