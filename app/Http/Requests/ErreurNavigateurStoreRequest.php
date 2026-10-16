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
     * requête finissait en 500. La pile l'est par ce que sa colonne tient
     * (`ErreurNavigateur::PILE_MAX_CARACTERES`), une fois coupée
     * (`prepareForValidation()`).
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
            'pile' => ['nullable', 'string', 'max:'.ErreurNavigateur::PILE_MAX_CARACTERES],
            'url' => ['required', 'string', 'max:2048'],
            'agent' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * Une pile plus longue que sa colonne se coupe plutôt que d'être
     * refusée : le rapport d'une erreur vaut mieux sans la fin de sa pile,
     * les appels les plus lointains, que perdu (#2071).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $pile = $this->input('pile');

        if (is_string($pile) && mb_strlen($pile) > ErreurNavigateur::PILE_MAX_CARACTERES) {
            $this->merge(['pile' => mb_substr($pile, 0, ErreurNavigateur::PILE_MAX_CARACTERES)]);
        }
    }
}
