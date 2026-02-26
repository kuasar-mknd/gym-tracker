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
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('achievements')->ignore($this->route('achievement')),
            ],
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'icon' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'threshold' => 'required|numeric',
            'category' => 'required|string|max:255',
        ];
    }
}
