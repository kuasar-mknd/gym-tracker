<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BodyPartMeasurementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'part' => ['sometimes', 'required', 'string', 'max:50'],
            'value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999.99'],
            'unit' => ['sometimes', 'required', 'string', 'in:cm,in'],
            'measured_at' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
