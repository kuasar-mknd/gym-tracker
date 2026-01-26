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

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body_part' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,recovering,healed'],
            'injured_at' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'healed_at' => ['nullable', 'date', 'after_or_equal:injured_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
