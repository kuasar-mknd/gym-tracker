<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Date;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

/**
 * Une date que la colonne qui la reçoit sait écrire (#2071).
 *
 * La règle `date` accepte toute date du calendrier, de l'an 1 à bien au-delà
 * de l'an 9999 (`+10000-01-01`), et MySQL refuse en mode strict ce que sa
 * colonne ne tient pas : l'écriture finissait en 500 au lieu d'un refus sur
 * le champ. Une colonne `TIMESTAMP` ne tient que du 1er janvier 1970 à
 * 00:00:01 au 19 janvier 2038 à 03:14:07, en UTC ; une colonne `DATE` ou
 * `DATETIME`, de l'an 1000 à l'an 9999. Une date recopiée ailleurs prend la
 * plage de sa destination : le début d'une séance (`DATETIME`) devient la
 * dernière séance du compte (`users.last_workout_at`, `TIMESTAMP`).
 *
 * La plage d'un horodatage garde un jour de marge à chaque bout : l'heure
 * s'écrit dans le fuseau de l'application, et la base la lit dans celui de
 * sa session, qui peut en différer d'un jour au plus.
 *
 * La valeur se lit comme Eloquent l'écrira (`asDateTime()`) : un nombre est
 * un horodatage Unix, une chaîne une date du fuseau de l'application. Une
 * chaîne dont le jour écrit en tête n'est pas celui qu'elle désigne est
 * refusée : PHP lit `10000-01-01` comme le 1er janvier 2000 à 10 h, que
 * `date` accepte, et la base recevait la chaîne telle quelle. La règle se
 * pose derrière `bail` et `date`, qui en refusent la forme ; une valeur
 * qu'elle ne sait pas lire est refusée aussi.
 * `LesBornesDesRequetesTiennentDansLaBaseTest` confronte chaque champ `date`
 * des requêtes à sa colonne.
 */
final readonly class DateQueSaColonneTient implements ValidationRule
{
    /**
     * Le premier jour d'un horodatage : le lendemain du 1er janvier 1970.
     */
    public const string PREMIER_JOUR_D_UN_HORODATAGE = '1970-01-02';

    /**
     * Le dernier jour d'un horodatage : l'avant-veille du 19 janvier 2038.
     */
    public const string DERNIER_JOUR_D_UN_HORODATAGE = '2038-01-17';

    /**
     * Le premier jour d'une colonne `DATE` ou `DATETIME`.
     */
    public const string PREMIER_JOUR_D_UNE_DATE = '1000-01-01';

    /**
     * Le dernier jour d'une colonne `DATE` ou `DATETIME`.
     */
    public const string DERNIER_JOUR_D_UNE_DATE = '9999-12-31';

    private function __construct(public string $premierJour, public string $dernierJour)
    {
    }

    /**
     * Pour une colonne `TIMESTAMP`, ou une date qui y sera recopiée.
     */
    public static function horodatage(): self
    {
        return new self(self::PREMIER_JOUR_D_UN_HORODATAGE, self::DERNIER_JOUR_D_UN_HORODATAGE);
    }

    /**
     * Pour une colonne `DATE` ou `DATETIME`.
     */
    public static function date(): self
    {
        return new self(self::PREMIER_JOUR_D_UNE_DATE, self::DERNIER_JOUR_D_UNE_DATE);
    }

    /**
     * Le premier instant admis, à la seconde, dans le fuseau de l'application.
     */
    public function premierInstant(): string
    {
        return $this->premierJour.' 00:00:00';
    }

    /**
     * Le dernier instant admis, à la seconde, dans le fuseau de l'application.
     */
    public function dernierInstant(): string
    {
        return $this->dernierJour.' 23:59:59';
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $instant = self::instant($value);
        $fuseau = config()->string('app.timezone');

        if ($instant instanceof CarbonInterface
            && $instant->greaterThanOrEqualTo(Date::parse($this->premierInstant(), $fuseau))
            && $instant->lessThanOrEqualTo(Date::parse($this->dernierInstant(), $fuseau))) {
            return;
        }

        $fail(sprintf(
            'Le champ :attribute doit être une date comprise entre le %s et le %s.',
            self::jourEnFrancais($this->premierJour),
            self::jourEnFrancais($this->dernierJour),
        ));
    }

    /**
     * Un jour écrit en toutes lettres (« 2 janvier 1970 », « 1er janvier 1000 »).
     */
    private static function jourEnFrancais(string $jour): string
    {
        $date = Date::parse($jour)->locale('fr');

        if (! $date instanceof CarbonInterface) {
            return $jour;
        }

        return $date->day === 1 ? '1er '.$date->translatedFormat('F Y') : $date->translatedFormat('j F Y');
    }

    /**
     * L'instant que désigne la valeur, lu comme Eloquent l'écrira, ou null
     * s'il ne se lit pas, ou pas au jour qu'elle écrit en tête.
     */
    private static function instant(mixed $valeur): ?CarbonInterface
    {
        $fuseau = config()->string('app.timezone');

        try {
            $instant = match (true) {
                is_int($valeur), is_float($valeur), is_string($valeur) && is_numeric($valeur) => Date::createFromTimestamp($valeur, $fuseau),
                is_string($valeur) => Date::parse($valeur, $fuseau),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($instant === null
            || ! is_string($valeur)
            || preg_match('/^\s*\+?(\d+)-(\d+)-(\d+)/', $valeur, $jour) !== 1) {
            return $instant;
        }

        return [(int) $jour[1], (int) $jour[2], (int) $jour[3]] === [$instant->year, $instant->month, $instant->day]
            ? $instant
            : null;
    }
}
