<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Carbon\CarbonTimeZone;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Un instant écrit à l'heure murale de l'application, avec son décalage dans
 * la colonne voisine (#2159).
 *
 * Le cast `datetime` n'écrit que l'heure murale (`Y-m-d H:i:s`) et la relit
 * dans le fuseau de l'application. Pendant l'heure que ce fuseau répète au
 * passage à l'heure d'hiver, les deux occurrences s'écrivent de la même façon,
 * et PHP relit toujours la seconde : un instant pris dans la première revenait
 * une heure plus tard. Une séance commencée dans cette heure et terminée
 * quarante minutes après était ramenée à son début relu, pour 0 min, et un
 * jeûne de seize heures en comptait quinze.
 *
 * L'heure murale s'écrit comme avant, dans le fuseau de l'application : toute
 * requête, borne ou expression SQL la lit toujours, et rien n'y change. Le
 * décalage de l'instant, en minutes, s'écrit à côté, et ne sert qu'à choisir
 * entre les occurrences de cette heure : à la lecture, il n'est retenu que
 * s'il redonne la même heure murale dans le fuseau de l'application. Une
 * ligne sans décalage (toute ligne d'avant #2159), ou dont le décalage ne va
 * pas avec son heure (une écriture passée par le constructeur de requêtes, un
 * fuseau d'application changé depuis), se lit donc exactement comme le cast
 * `datetime` la lisait.
 *
 * Un instant donné dans un autre fuseau s'écrit à l'heure murale de
 * l'application, comme `now()` et les requêtes (`RameneLesDatesAuFuseauDeLApplication`)
 * la donnent déjà : le cast `datetime` en écrivait l'heure murale du fuseau
 * reçu, relue ensuite comme une heure de l'application (#1952).
 *
 * L'instant rendu est un `Carbon` du fuseau de l'application, une copie à
 * chaque lecture (`withoutObjectCaching`), comme le cast `datetime` : le
 * modifier ne change pas le modèle. Le modèle sérialise un instant ainsi
 * casté comme ses autres dates (`serializeDate()`, `…Z`).
 *
 * @implements CastsAttributes<Carbon, mixed>
 */
final class InstantAvecSonDecalage implements CastsAttributes
{
    /**
     * Le format de l'heure murale, celui des colonnes `DATETIME` et `TIMESTAMP`.
     */
    public const string FORMAT_DE_L_HEURE_MURALE = 'Y-m-d H:i:s';

    /**
     * Le plus grand écart à UTC qu'un décalage lu peut porter, en minutes : au-delà,
     * il ne désigne aucune occurrence, et la ligne se lit comme sans décalage.
     */
    public const int DECALAGE_MAX_MINUTES = 18 * 60;

    /**
     * Une copie à chaque lecture, comme le cast `datetime`. Eloquent la lit par
     * son nom (`$caster->withoutObjectCaching ?? false`), sans appel PHP que le
     * détecteur de code mort verrait.
     */
    // @phpstan-ignore shipmonk.deadProperty.neverRead
    public bool $withoutObjectCaching = true;

    /**
     * @param  string  $colonneDuDecalage  la colonne qui garde le décalage, en minutes
     */
    public function __construct(private readonly string $colonneDuDecalage)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return self::lire($value, $attributes[$this->colonneDuDecalage] ?? null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => $value, $this->colonneDuDecalage => null];
        }

        $instant = self::commeLeCastDatetime($value)->setTimezone(self::fuseau());

        return [
            $key => $instant->format(self::FORMAT_DE_L_HEURE_MURALE),
            $this->colonneDuDecalage => intdiv($instant->getOffset(), 60),
        ];
    }

    /**
     * L'instant qu'une heure murale et son décalage désignent : l'occurrence que
     * le décalage nomme quand il redonne cette heure dans le fuseau de
     * l'application, sinon l'heure lue dans ce fuseau, comme le cast `datetime`.
     *
     * Publique pour une lecture qui ne passe pas par le modèle (`toBase()`).
     */
    public static function lire(mixed $murale, mixed $decalage): Carbon
    {
        $commeAvant = self::commeLeCastDatetime($murale);
        $minutes = self::minutes($decalage);

        if ($minutes === null || ! is_string($murale)) {
            return $commeAvant;
        }

        $fuseauDuDecalage = CarbonTimeZone::createFromMinuteOffset($minutes);

        if (! $fuseauDuDecalage instanceof CarbonTimeZone) {
            return $commeAvant;
        }

        try {
            $occurrence = Carbon::createFromFormat(self::FORMAT_DE_L_HEURE_MURALE, $murale, $fuseauDuDecalage);
        } catch (InvalidArgumentException) {
            return $commeAvant;
        }

        if (! $occurrence instanceof Carbon) {
            return $commeAvant;
        }

        $occurrence->setTimezone(self::fuseau());

        return $occurrence->format(self::FORMAT_DE_L_HEURE_MURALE) === $commeAvant->format(self::FORMAT_DE_L_HEURE_MURALE)
            ? $occurrence
            : $commeAvant;
    }

    /**
     * Une valeur lue comme le cast `datetime` la lit (`HasAttributes::asDateTime()`) :
     * une date telle quelle, un nombre comme un horodatage Unix, une chaîne au
     * format de la base, puis toute autre forme, celle qui porte son décalage
     * comprise ; une heure sans décalage est une heure de l'application.
     */
    private static function commeLeCastDatetime(mixed $valeur): Carbon
    {
        if ($valeur instanceof DateTimeInterface) {
            return Carbon::instance($valeur);
        }

        if (is_int($valeur) || is_float($valeur) || (is_string($valeur) && is_numeric($valeur))) {
            return Carbon::createFromTimestamp($valeur, self::fuseau());
        }

        if (! is_string($valeur)) {
            throw new InvalidArgumentException(sprintf('Un instant se lit d’une date ou d’une chaîne, pas de %s.', get_debug_type($valeur)));
        }

        try {
            $lue = Carbon::createFromFormat(self::FORMAT_DE_L_HEURE_MURALE, $valeur, self::fuseau());
        } catch (InvalidFormatException) {
            $lue = null;
        }

        return $lue instanceof Carbon ? $lue : Carbon::parse($valeur, self::fuseau());
    }

    /**
     * Le décalage lu, en minutes, ou null quand il n'en désigne aucun.
     */
    private static function minutes(mixed $decalage): ?int
    {
        $minutes = match (true) {
            is_int($decalage) => $decalage,
            is_string($decalage) && preg_match('/^-?\d{1,5}$/', $decalage) === 1 => (int) $decalage,
            default => null,
        };

        return $minutes !== null && abs($minutes) <= self::DECALAGE_MAX_MINUTES ? $minutes : null;
    }

    private static function fuseau(): string
    {
        return config()->string('app.timezone');
    }
}
