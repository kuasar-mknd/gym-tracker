<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Supplement;
use Illuminate\Foundation\Http\FormRequest;

class SupplementStoreRequest extends FormRequest
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
     * Le stock et le seuil d'alerte sont bornés (`Supplement::DOSES_MAX`) :
     * au-delà de la colonne (int), la requête finissait en 500.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'servings_remaining' => ['required', 'integer', 'min:0', 'max:'.Supplement::DOSES_MAX],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:'.Supplement::DOSES_MAX],
        ];
    }
}
