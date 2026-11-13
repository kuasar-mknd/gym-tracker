<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Goal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La cible d'un objectif « N séances par semaine » : un nombre entier de
 * séances, d'une au moins (zéro serait atteint d'emblée) à deux par jour
 * (`Goal::SEANCES_PAR_SEMAINE_MAX`).
 *
 * `GoalStoreRequest` et le formulaire du panneau la partagent. Elle ne juge que
 * des nombres : une cible qui n'en est pas un se refuse par `numeric`.
 */
final readonly class CibleEnSeancesParSemaine implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $cible = (float) $value;

        if ($cible !== floor($cible) || $cible < 1 || $cible > Goal::SEANCES_PAR_SEMAINE_MAX) {
            $fail('Le champ :attribute doit être un nombre entier de séances, de 1 à '.Goal::SEANCES_PAR_SEMAINE_MAX.'.');
        }
    }
}
