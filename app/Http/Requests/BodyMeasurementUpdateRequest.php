<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BodyMeasurementUpdateRequest extends FormRequest
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
            'weight' => ['sometimes', 'required', 'numeric', 'min:1', 'max:500'],
            'body_fat' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'measured_at' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
