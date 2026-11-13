<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Casts\InstantAvecSonDecalage;
use Carbon\CarbonInterface;

/**
 * Une heure du formulaire d'une séance au panneau, lue comme le cast
 * `InstantAvecSonDecalage` la lit une fois enregistrée (#2159).
 */
final class HeureDuChampDeSeance
{
    /**
     * L'instant qu'une heure du champ désignerait une fois enregistrée : celle
     * de `avecLeDecalage()`, lue par `InstantAvecSonDecalage::lire()`. Nul
     * quand l'heure n'en est pas une, que d'autres règles refusent.
     */
    public static function instant(mixed $heure, ?CarbonInterface $enregistre): ?CarbonInterface
    {
        try {
            return InstantAvecSonDecalage::lire($heure, self::decalageQueGarde(is_string($heure) ? $heure : '', $enregistre));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * L'heure du champ, telle que Filament la rend (l'heure murale de
     * l'application, `Y-m-d H:i:s`), suivie du décalage de l'instant
     * enregistré quand ce décalage redonne cette heure dans le fuseau de
     * l'application, c'est-à-dire quand le cast le retiendrait. Une autre
     * heure, ou une séance sans cet instant, part telle quelle.
     *
     * Une heure inchangée garde ainsi son occurrence. Une heure changée garde
     * celle de l'instant enregistré tant que son décalage va avec elle : une
     * séance de la première occurrence dont on retouche le début ou la fin
     * dans l'heure répétée y reste. Lue seule, cette heure passait dans la
     * seconde occurrence, à côté de l'autre champ resté dans la première :
     * l'écran montrait le début avant la fin, et la fin s'enregistrait avant
     * le début. Une heure dont le décalage enregistré ne va plus (passée de
     * l'été à l'hiver) se lit seule, comme toute heure sans décalage.
     */
    public static function avecLeDecalage(mixed $heure, ?CarbonInterface $enregistre): mixed
    {
        $garde = is_string($heure) && self::decalageQueGarde($heure, $enregistre) !== null;

        return $garde ? $heure.$enregistre?->copy()->setTimezone(config()->string('app.timezone'))->format('P') : $heure;
    }

    /**
     * Le décalage de l'instant enregistré, en minutes, quand il redonne
     * `$heure` dans le fuseau de l'application ; nul sinon, ou sans instant
     * enregistré.
     */
    private static function decalageQueGarde(string $heure, ?CarbonInterface $enregistre): ?int
    {
        $decalage = $enregistre?->copy()->setTimezone(config()->string('app.timezone'))->getOffset();

        return $decalage !== null && InstantAvecSonDecalage::lire($heure, intdiv($decalage, 60))->getOffset() === $decalage
            ? intdiv($decalage, 60)
            : null;
    }
}
