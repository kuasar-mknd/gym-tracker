<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
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
            'start_time' => ['sometimes', 'date'],
            'target_duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'string'],
            'end_time' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:active,completed,broken'],
        ];
    }
}
