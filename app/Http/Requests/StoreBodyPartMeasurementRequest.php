<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBodyPartMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'part' => ['required', 'string', 'max:50'], // Allow flexibility but keep it sane
            'value' => ['required', 'numeric', 'min:0', 'max:500'], // cm
            'measured_at' => ['required', 'date'],
        ];
    }
}
