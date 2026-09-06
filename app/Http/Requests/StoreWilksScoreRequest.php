<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWilksScoreRequest extends FormRequest
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
            'body_weight' => ['required', 'numeric', 'min:1', 'max:500'],
            'lifted_weight' => ['required', 'numeric', 'min:1', 'max:1000'],
            'gender' => ['required', 'string', 'in:male,female'],
            'unit' => ['required', 'string', 'in:kg,lbs'],
        ];
    }
}
