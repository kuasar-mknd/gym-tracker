<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Ce qui vouvoie dans un texte français : l'application tutoie (#1980), ses
 * courriels (#2118) comme ses écrans et ses messages (#2119).
 *
 * Un mot qui vouvoie se reconnaît seul : « vous », « votre », « vos »,
 * « vôtre », « êtes », et toute forme en « -ez », qui est en français celle
 * de la deuxième personne du pluriel (« Veuillez », « Réinitialisez »,
 * « Commencez », « Terminez-la »). Quatre mots courants finissent en « -ez »
 * sans en être une : « chez », « assez », « nez » et « rez ». « faites » et
 * « dites » n'y sont pas : l'application les écrit en participes (« les
 * séances faites »).
 */
final class Vouvoiement
{
    /**
     * Un mot entier qui vouvoie, quelle que soit sa casse.
     */
    public const string MOTIF = '/(?<![\p{L}\p{N}_])(?!(?:chez|assez|nez|rez)(?![\p{L}\p{N}_]))(?:vous|votre|vos|vôtres?|êtes|\p{L}*ez)(?![\p{L}\p{N}_])/iu';

    /**
     * Les mots de `$texte` qui vouvoient, dans l'ordre.
     *
     * @return list<string>
     */
    public static function mots(string $texte): array
    {
        preg_match_all(self::MOTIF, $texte, $trouves);

        return array_values($trouves[0]);
    }
}
