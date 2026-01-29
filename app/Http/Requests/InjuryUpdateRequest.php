<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InjuryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body_part' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,recovering,healed'],
            'pain_level' => ['required', 'integer', 'min:1', 'max:10'],
            'occurred_at' => ['required', 'date'],
            'healed_at' => ['nullable', 'date', 'after_or_equal:occurred_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
