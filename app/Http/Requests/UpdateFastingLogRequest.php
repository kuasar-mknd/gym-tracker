<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFastingLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ownership check is usually done in Controller or Policy,
        // but FormRequest authorize can also handle it if we have route binding.
        // For now, I'll rely on the Controller or Policy to check strict ownership.
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
            'target_duration_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'method' => ['sometimes', 'string', 'max:50'],
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date'],
        ];
    }
}
