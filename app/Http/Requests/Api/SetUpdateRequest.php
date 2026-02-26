<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

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
            'weight' => 'nullable|numeric|min:0|max:1000',
            'reps' => 'nullable|integer|min:0|max:1000',
            'duration_seconds' => 'nullable|integer|min:0|max:86400',
            'distance_km' => 'nullable|numeric|min:0|max:1000',
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ];
    }
}
