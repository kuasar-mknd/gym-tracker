<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ErreurNavigateur;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ErreurNavigateurStoreRequest extends FormRequest
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
     * La ligne et la colonne sont bornées (`ErreurNavigateur::POSITION_MAX`) :
     * au-delà de la colonne (unsigned int), la base refusait l'écriture et la
     * requête finissait en 500.
     *
     * @return array<string, list<\Illuminate\Validation\Rules\In|string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ErreurNavigateur::TYPES)],
            'message' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:2048'],
            'ligne' => ['nullable', 'integer', 'min:0', 'max:'.ErreurNavigateur::POSITION_MAX],
            'colonne' => ['nullable', 'integer', 'min:0', 'max:'.ErreurNavigateur::POSITION_MAX],
            'pile' => ['nullable', 'string', 'max:20000'],
            'url' => ['required', 'string', 'max:2048'],
            'agent' => ['nullable', 'string', 'max:512'],
        ];
    }
}
