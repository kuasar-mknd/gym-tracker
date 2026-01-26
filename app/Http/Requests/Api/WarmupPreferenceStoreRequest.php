<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class WarmupPreferenceStoreRequest extends FormRequest
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
            'bar_weight' => ['required', 'numeric', 'min:0'],
            'rounding_increment' => ['required', 'numeric', 'min:0'],
            'steps' => ['required', 'array'],
            'steps.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'steps.*.reps' => ['required', 'integer', 'min:1'],
            'steps.*.label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
