<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Un nombre ou une date lus en base, sous la forme où le diagnostic les rend.
 * Les lignes sont lues sans modèle (`toBase()`) : chaque colonne arrive en
 * `mixed`, et ce qui n'a pas la forme attendue devient `null`.
 */
final class ValeurLue
{
    /**
     * Un instant dans le fuseau de l'application, au format ISO 8601 ;
     * `null` s'il n'y en a pas ou s'il ne se lit pas. Une chaîne sans
     * décalage (une date de la base) est lue dans ce fuseau ; une chaîne
     * qui en porte un y est ramenée.
     */
    public static function instant(mixed $valeur): ?string
    {
        $fuseau = config()->string('app.timezone');

        try {
            return match (true) {
                $valeur instanceof CarbonInterface => $valeur->toImmutable()->setTimezone($fuseau)->toIso8601String(),
                is_string($valeur) && $valeur !== '' => CarbonImmutable::parse($valeur, $fuseau)->setTimezone($fuseau)->toIso8601String(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Un entier, `null` s'il n'en est pas un.
     */
    public static function entier(mixed $valeur): ?int
    {
        return match (true) {
            is_int($valeur) => $valeur,
            is_string($valeur) && preg_match('/^-?\d{1,18}$/D', $valeur) === 1 => (int) $valeur,
            default => null,
        };
    }

    /**
     * Une durée en secondes, arrondie au centième, `null` si ce n'est pas un
     * nombre.
     */
    public static function duree(mixed $valeur): ?float
    {
        return match (is_numeric($valeur)) {
            true => round((float) $valeur, 2),
            false => null,
        };
    }

    /**
     * Une chaîne, vide si ce n'en est pas une.
     */
    public static function texte(mixed $valeur): string
    {
        return is_string($valeur) ? $valeur : '';
    }

    /**
     * Un morceau de texte que la base a coupé en octets : une chaîne, dont
     * un caractère que la coupe a tranché est remplacé.
     */
    public static function morceau(mixed $valeur): string
    {
        return mb_scrub(self::texte($valeur), 'UTF-8');
    }
}
