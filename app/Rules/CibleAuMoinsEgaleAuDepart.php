<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La cible d'un objectif qui monte : au moins égale à son départ.
 *
 * Le record, la meilleure séance et le nombre de séances ne font que monter
 * avec l'activité : sous une cible plus basse que le départ, la barre
 * comptait la descente et l'état la montée, et l'objectif pouvait rester à la
 * barre pleine sans être atteint (#2164). La mensuration, qui seule descend,
 * n'en passe pas par là : l'appelant ne pose la règle qu'hors mensuration.
 *
 * `GoalStoreRequest` et le formulaire du panneau partagent cette règle, qui
 * reçoit le départ saisi dans le même envoi et compare les deux nombres. Une
 * comparaison entre deux champs numériques passe toujours par un objet de
 * règle de ce genre, lisible et partagé (`.ai/rules/requests.md`).
 *
 * Elle ne juge que deux nombres : une cible ou un départ qui n'en est pas un
 * se refuse par sa propre règle (`numeric`), et un départ absent ne se compare
 * à rien.
 */
final readonly class CibleAuMoinsEgaleAuDepart implements ValidationRule
{
    public function __construct(private mixed $depart)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! is_numeric($this->depart)) {
            return;
        }

        if ((float) $value < (float) $this->depart) {
            $fail('Le champ :attribute doit être au moins égal à la valeur de départ.');
        }
    }
}
