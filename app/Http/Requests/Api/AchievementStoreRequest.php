<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Achievement;
use Illuminate\Foundation\Http\FormRequest;

class AchievementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Achievement::class) ?? false;
    }

    /**
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
            'threshold' => ['required', 'numeric'],
            'category' => ['required', 'string', 'max:255'],
        ];
    }
}
