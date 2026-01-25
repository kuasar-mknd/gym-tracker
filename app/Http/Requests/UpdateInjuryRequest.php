<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInjuryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body_part' => ['required', 'string', 'max:255'],
            'diagnosis' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:low,medium,high'],
            'status' => ['required', 'string', 'in:active,recovering,healed'],
            'pain_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'occurred_at' => ['required', 'date'],
            'healed_at' => ['nullable', 'date', 'after_or_equal:occurred_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
