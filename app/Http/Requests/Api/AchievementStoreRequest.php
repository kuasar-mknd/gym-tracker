<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property-read string $slug
 * @property-read string $name
 * @property-read string $description
 * @property-read string $icon
 * @property-read string $type
 * @property-read float $threshold
 * @property-read string $category
 */
class AchievementStoreRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'unique:achievements,slug'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'icon' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'threshold' => ['required', 'numeric', 'min:0'],
            'category' => ['required', 'string', 'max:255'],
        ];
    }
}
