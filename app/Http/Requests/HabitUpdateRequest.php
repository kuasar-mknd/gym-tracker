<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HabitUpdateRequest extends FormRequest
{
    /**
     * L'autorisation vit dans le contrôleur ; le refus sur une ressource
     * d'autrui, validation comprise, est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:255'],
            'goal_times_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'archived' => ['boolean'],
        ];
    }
}
