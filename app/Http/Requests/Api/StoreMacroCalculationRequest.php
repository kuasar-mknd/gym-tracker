<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreMacroCalculationRequest extends FormRequest
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
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'], // cm
            'weight' => ['required', 'numeric', 'min:20', 'max:300'], // kg
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ];
    }
}
