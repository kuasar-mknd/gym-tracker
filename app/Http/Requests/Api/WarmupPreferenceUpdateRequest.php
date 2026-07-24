<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class WarmupPreferenceUpdateRequest extends FormRequest
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
            'bar_weight' => ['sometimes', 'numeric', 'min:0'],
            'rounding_increment' => ['sometimes', 'numeric', 'min:0'],
            'steps' => ['sometimes', 'array'],
            'steps.*.percent' => ['required_with:steps', 'numeric', 'min:0', 'max:100'],
            'steps.*.reps' => ['required_with:steps', 'integer', 'min:1'],
            'steps.*.label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
