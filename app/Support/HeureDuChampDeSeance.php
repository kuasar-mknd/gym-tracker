<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Casts\InstantAvecSonDecalage;
use Carbon\CarbonInterface;
use DateTimeImmutable;

/**
 * Une heure du formulaire d'une séance au panneau, lue comme le cast
 * `InstantAvecSonDecalage` la lit une fois enregistrée (#2159).
 */
final class HeureDuChampDeSeance
{
    /**
     * L'instant qu'une heure du champ désignerait une fois enregistrée : cette
     * heure lue par `InstantAvecSonDecalage::lire()` avec le décalage de
     * l'instant enregistré, que `lire()` ne retient que s'il redonne cette
     * heure dans le fuseau de l'application. Nul quand l'heure n'existe pas
     * (`2026-02-30`, une année à cinq chiffres, un texte).
     */
    public static function instant(mixed $heure, ?CarbonInterface $enregistre): ?CarbonInterface
    {
        $format = InstantAvecSonDecalage::FORMAT_DE_L_HEURE_MURALE;
        $lue = is_string($heure) ? DateTimeImmutable::createFromFormat($format, $heure) : false;

        return $lue !== false && $lue->format($format) === $heure
            ? InstantAvecSonDecalage::lire($heure, $enregistre?->copy()->setTimezone(config()->string('app.timezone'))->utcOffset())
            : null;
    }

    /**
     * L'heure du champ, telle que Filament la rend (l'heure murale de
     * l'application), suivie du décalage de l'instant qu'elle désigne : une
     * heure inchangée garde son occurrence, une heure changée garde celle de
     * l'instant enregistré tant que son décalage va avec elle. Lue seule, une
     * heure de l'heure répétée passait dans la seconde occurrence, à côté de
     * l'autre champ resté dans la première : la fin s'enregistrait avant le
     * début. Une heure qui n'existe pas part telle quelle.
     */
    public static function avecLeDecalage(mixed $heure, ?CarbonInterface $enregistre): mixed
    {
        return self::instant($heure, $enregistre)?->format(InstantAvecSonDecalage::FORMAT_DE_L_HEURE_MURALE.'P') ?? $heure;
    }
}
