<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Workout;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La fin choisie d'une séance oubliée tient entre son début et maintenant
 * (#2319) : une fin d'avant le début donnerait une durée négative, une fin à
 * venir clorait la séance à une heure qui n'est pas venue.
 *
 * L'instant de maintenant se lit par une fermeture, et seulement quand le
 * champ est jugé : la requête le lit une fois et le passe à l'action
 * (`WorkoutFinishRequest::instantLu()`, `.ai/rules/horloge.md`). La séance se
 * lit parmi celles du compte : une référence qui n'en est pas une (absente,
 * d'un autre compte, `{ enAttenteDe }`) est refusée par `workout_id`, pas ici,
 * et le début ne se juge alors pas. Les instants se comparent comme des
 * instants : le début relu garde son décalage (`InstantAvecSonDecalage`).
 */
final readonly class FinChoisieDansLaSeance implements ValidationRule
{
    /**
     * @param  Closure(): CarbonInterface  $maintenant
     */
    public function __construct(private mixed $seanceId, private ?int $compteId, private Closure $maintenant)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $fin = CarbonImmutable::parse($value);

        if ($fin->greaterThan(($this->maintenant)())) {
            $fail('La fin de la séance ne peut pas être dans le futur.');

            return;
        }

        $identifiant = is_string($this->seanceId) && ctype_digit($this->seanceId) ? (int) $this->seanceId : $this->seanceId;

        if (! is_int($identifiant)) {
            return;
        }

        $debut = Workout::query()->where('user_id', $this->compteId)->whereKey($identifiant)->first()?->started_at;

        if ($debut !== null && $fin->lessThan($debut)) {
            $fail('La fin de la séance ne peut pas précéder son début.');
        }
    }
}
