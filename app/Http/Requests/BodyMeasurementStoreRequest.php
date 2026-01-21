<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BodyMeasurementStoreRequest extends FormRequest
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
            'weight' => ['required', 'numeric', 'min:1', 'max:500'],
            'body_fat' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'measured_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'parts' => ['nullable', 'array'],
            'parts.*.part' => ['required', 'string', 'max:50'],
            'parts.*.value' => ['required', 'numeric', 'min:0', 'max:500'],
        ];
    }
}
