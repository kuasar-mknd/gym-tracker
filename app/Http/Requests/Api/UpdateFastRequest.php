<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Fast;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
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
     * La durée cible est bornée comme à la création
     * (`Fast::DUREE_CIBLE_MAX_MINUTES`).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date'],
            'target_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:'.Fast::DUREE_CIBLE_MAX_MINUTES],
            'type' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,completed,broken'],
        ];
    }
}
