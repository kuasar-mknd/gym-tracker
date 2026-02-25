<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'slug' => [
                'sometimes',
                'string',
                Rule::unique('achievements', 'slug')->ignore($achievement->id),
                'max:255',
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'icon' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:count,streak,weight_record,volume_total'],
            'threshold' => ['sometimes', 'numeric', 'min:0'],
            'category' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
