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
            'start_time' => ['required', 'date'],
            'target_duration_hours' => ['required', 'numeric', 'min:1', 'max:168'], // Max 1 week
            'type' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
