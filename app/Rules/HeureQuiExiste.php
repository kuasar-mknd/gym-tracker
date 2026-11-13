<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\HeureDuChampDeSeance;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Le jour et l'heure d'un champ de séance existent : `2026-02-30` et une année
 * à cinq chiffres n'existent pas, `2026-03-29 02:30` (le trou du printemps)
 * existe, et s'enregistre à 03:30.
 */
final readonly class HeureQuiExiste implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! HeureDuChampDeSeance::instant($value, null) instanceof CarbonInterface) {
            $fail('Cette date n’existe pas.');
        }
    }
}
