<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AchievementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\Achievement $achievement */
        $achievement = $this->route('achievement');

        return $this->user()?->can('update', $achievement) ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var \App\Models\Achievement $achievement */
        $achievement = $this->route('achievement');

        return [
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('achievements', 'slug')->ignore($achievement->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'icon' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'threshold' => ['sometimes', 'required', 'numeric'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
