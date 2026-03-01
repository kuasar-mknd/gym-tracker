<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAchievementRequest extends FormRequest
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
        /** @var \App\Models\Achievement $achievement */
        $achievement = $this->route('achievement');

        return [
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'unique:achievements,slug,'.$achievement->id],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'icon' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'threshold' => ['sometimes', 'required', 'numeric'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
