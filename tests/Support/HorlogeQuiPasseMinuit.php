<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;

/**
 * Une horloge qui passe minuit au milieu d'un calcul.
 *
 * Les deux bornes d'une fenêtre tirées de deux lectures de l'horloge tombent
 * sur deux jours, ou sur deux semaines, quand minuit passe entre elles : la
 * fenêtre s'élargit d'autant. L'endroit exact dépend de l'ordre des appels,
 * cache compris ; un jeu de données qui fait passer minuit après chacune des
 * lectures les essaie toutes (#2017).
 */
final class HorlogeQuiPasseMinuit
{
    /**
     * Rend `$avant` aux `$lectures` premières lectures de l'horloge, puis
     * `$apres`.
     */
    public static function apres(int $lectures, string $avant, string $apres): void
    {
        $instantAvant = Carbon::parse($avant);
        $instantApres = Carbon::parse($apres);
        $lues = 0;

        Carbon::setTestNow(static function () use (&$lues, $lectures, $instantAvant, $instantApres): Carbon {
            $lues++;

            return ($lues <= $lectures ? $instantAvant : $instantApres)->copy();
        });
    }
}
