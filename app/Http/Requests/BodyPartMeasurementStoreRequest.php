<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BodyPartMeasurementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'part' => ['required', 'string', 'max:50'],
            'value' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'unit' => ['required', 'string', 'in:cm,in'],
            'measured_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
