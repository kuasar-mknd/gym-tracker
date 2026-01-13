<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetUpdateRequest extends FormRequest
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
            'weight' => ['nullable', 'numeric', 'min:0'],
            'reps' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'is_warmup' => ['nullable', 'boolean'],
        ];
    }
}
