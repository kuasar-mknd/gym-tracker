<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\WaterLog;
use Illuminate\Foundation\Http\FormRequest;

class StoreWaterLogRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Une prise d'eau est bornée (`WaterLog::QUANTITE_MAX_ML`) : au-delà de la
     * colonne (int), la base refusait l'écriture et la requête finissait en 500.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.WaterLog::QUANTITE_MAX_ML],
            'consumed_at' => ['required', 'date'],
        ];
    }

    /**
     * L'ajout d'eau envoie l'heure en UTC : sans conversion, un verre bu après
     * minuit comptait pour la veille (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplication(['consumed_at']);
    }
}
