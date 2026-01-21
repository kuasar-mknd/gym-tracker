<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HabitUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $habit = $this->route('habit');
        return $habit && $this->user()->id === $habit->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string'],
            'icon' => ['nullable', 'string'],
            'goal_times_per_week' => ['required', 'integer', 'min:1', 'max:7'],
            'archived' => ['boolean'],
        ];
    }
}
