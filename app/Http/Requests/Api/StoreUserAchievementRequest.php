<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserAchievementRequest extends FormRequest
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
     * Validates:
     * - `achievement_id`: Required, must be a valid existing achievement ID.
     * - `achieved_at`: Optional, must be a valid date.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'achievement_id' => ['required', 'integer', 'exists:achievements,id'],
            'achieved_at' => ['sometimes', 'date'],
        ];
    }
}
