<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WarmupPreference;
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
     * Les bornes d'une montée en charge (`WarmupPreference`) : le poids de la
     * barre et l'arrondi tiennent dans leurs colonnes (decimal(8,2)), et les
     * paliers, gardés en JSON, sont comptés.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bar_weight' => ['required', 'numeric', 'min:0', 'max:'.WarmupPreference::POIDS_DE_BARRE_MAX_KG],
            'rounding_increment' => ['required', 'numeric', 'min:0', 'max:'.WarmupPreference::ARRONDI_MAX_KG],
            'steps' => ['required', 'array', 'max:'.WarmupPreference::PALIERS_MAX],
            'steps.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'steps.*.reps' => ['required', 'integer', 'min:1', 'max:'.WarmupPreference::REPETITIONS_MAX_PAR_PALIER],
            'steps.*.label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
