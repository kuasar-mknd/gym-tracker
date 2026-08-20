<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\SupplementStoreRequest as ValidationPartagee;

class SupplementStoreRequest extends ValidationPartagee
{
    /**
     * Determine if the user is authorized to make this request.
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
    /**
     * Les memes regles que le chemin web, sans rien de plus.
     *
     * Les deux classes portaient une copie mot pour mot. Elles s'accordaient
     * aujourd'hui, mais rien ne les y obligeait : durcir une regle d'un cote
     * sans l'autre rendait la contrainte contournable en changeant de porte
     * d'entree — c'est ce qui etait arrive a `StoreWilksScoreRequest` (#1378).
     *
     * Par heritage, sur le modele de `Api\DailyJournalStoreRequest`.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    #[\Override]
    public function rules(): array
    {
        return parent::rules();
    }
}
