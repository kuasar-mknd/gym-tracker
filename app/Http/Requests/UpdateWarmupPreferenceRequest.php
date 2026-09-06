<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarmupPreferenceRequest extends FormRequest
{
    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
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
            'bar_weight' => ['required', 'numeric', 'min:0'],
            'rounding_increment' => ['required', 'numeric', 'min:0'],
            'steps' => ['required', 'array'],
            'steps.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'steps.*.reps' => ['required', 'integer', 'min:1'],
            'steps.*.label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
