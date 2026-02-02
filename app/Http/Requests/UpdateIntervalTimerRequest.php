<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntervalTimerRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'work_seconds' => ['sometimes', 'required', 'integer', 'min:1'],
            'rest_seconds' => ['sometimes', 'required', 'integer', 'min:0'],
            'rounds' => ['sometimes', 'required', 'integer', 'min:1'],
            'warmup_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
