<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Set;
use Illuminate\Foundation\Http\FormRequest;

class SetUpdateRequest extends FormRequest
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
     * Les valeurs d'une série sont bornées par les plafonds métier de `Set`,
     * sous la capacité de leurs colonnes : au-delà, la base refusait
     * l'écriture et la requête finissait en 500, que la file hors ligne prend
     * pour une erreur passagère à réessayer, au lieu d'un refus lisible.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weight' => 'nullable|numeric|min:0|max:'.Set::POIDS_MAX_KG,
            'reps' => 'nullable|integer|min:0|max:'.Set::REPETITIONS_MAX,
            'duration_seconds' => 'nullable|integer|min:0|max:'.Set::DUREE_MAX_SECONDES,
            'distance_km' => 'nullable|numeric|min:0|max:'.Set::DISTANCE_MAX_KM,
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ];
    }
}
