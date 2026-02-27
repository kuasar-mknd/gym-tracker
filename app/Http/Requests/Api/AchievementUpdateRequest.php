<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Achievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AchievementUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Achievement|null $achievement */
        $achievement = $this->route('achievement');

        return $this->user()?->can('update', $achievement) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Achievement|null $achievement */
        $achievement = $this->route('achievement');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('achievements', 'slug')->ignore($achievement?->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'icon' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:255'],
            'threshold' => ['sometimes', 'numeric', 'min:0'],
            'category' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
