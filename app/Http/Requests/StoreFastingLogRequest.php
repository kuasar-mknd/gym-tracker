<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFastingLogRequest extends FormRequest
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
            'target_duration_hours' => ['required', 'integer', 'min:1', 'max:168'], // Up to 7 days
            'method' => ['required', 'string', 'max:50'],
            'start_time' => ['nullable', 'date'],
        ];
    }
}
