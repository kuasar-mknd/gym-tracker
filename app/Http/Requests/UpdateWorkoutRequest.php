<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Rules\DateQueSaColonneTient;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkoutRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

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
     * Le début se change, il ne s'efface pas : la colonne le demande, et un
     * début vide ou nul passait la validation pour finir en erreur 500 à
     * l'écriture (#2071). Absent, il reste ce qu'il est. Sa colonne est un
     * `DATETIME`, mais il devient la dernière séance du compte
     * (`users.last_workout_at`, un `TIMESTAMP`) : il prend la plage d'un
     * horodatage (`DateQueSaColonneTient`).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'started_at' => ['bail', 'sometimes', 'required', 'date', DateQueSaColonneTient::horodatage()],
            'notes' => 'nullable|string|max:1000',
            'is_finished' => 'nullable|boolean',
        ];
    }

    /**
     * Les réglages de la séance envoient son début en UTC : sans conversion, la
     * séance reculait de l'écart UTC à chaque enregistrement (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplication(['started_at']);
    }
}
