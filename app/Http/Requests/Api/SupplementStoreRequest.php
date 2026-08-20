<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\SupplementStoreRequest as ValidationPartagee;

/**
 * Les memes regles que le chemin web, sans rien de plus.
 *
 * Les deux classes portaient une copie mot pour mot. Elles s'accordaient
 * aujourd'hui, mais rien ne les y obligeait : durcir une regle d'un cote sans
 * l'autre rendait la contrainte contournable en changeant de porte d'entree —
 * c'est ce qui etait arrive a `StoreWilksScoreRequest` (#1378).
 *
 * Par heritage, sur le modele de `Api\DailyJournalStoreRequest`. Seul
 * `authorize()` reste redefini, et dans le sens du durcissement.
 */
class SupplementStoreRequest extends ValidationPartagee
{
    /**
     * Exige un appelant identifie.
     *
     * Le parent rend `true` : cote web, la session a deja fait ce travail. Une
     * requete d'API peut arriver sans jeton valide, d'ou ce controle en plus.
     */
    #[\Override]
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
