<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DailyJournalStoreRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'content' => ['nullable', 'string', 'max:5000'],
            'mood_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sleep_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'stress_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'energy_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'motivation_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'nutrition_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'training_intensity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
