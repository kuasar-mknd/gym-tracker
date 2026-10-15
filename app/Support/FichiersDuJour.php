<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Les fichiers du jour que le canal `daily` écrit dans son dossier, un par
 * jour et par conteneur : `nom-AAAA-MM-JJ.log`, l'extension étant celle du
 * chemin du canal, et la date celle des lignes du fichier.
 *
 * Le motif est celui que Monolog cherche pour son propre ménage, sans le nom
 * du conteneur : le dossier est partagé, et la purge prend les fichiers de
 * tous (#2053). Un dossier, un lien ou une date impossible (`2026-02-30`) n'en
 * sont pas : Monolog n'écrit que des fichiers, à des dates réelles. Le reste
 * du dossier (le fichier du canal `single`, le journal du pilote de
 * navigateur, une archive) ne porte pas ce motif.
 */
final class FichiersDuJour
{
    /**
     * Les fichiers du jour du dossier de ce canal datés de ce jour ou d'avant,
     * le jour s'écrivant `AAAA-MM-JJ`. Un dossier absent, ou qui ne se lit
     * pas, lève une exception plutôt que de passer pour un dossier sans
     * fichier : `glob()` les rend tous deux vides, et ne rend `false` que pour
     * une autre erreur, qui la lève aussi.
     *
     * @return list<string>
     *
     * @throws RuntimeException
     */
    public static function datesAuPlusLe(string $cheminDuCanal, string $dernierJour): array
    {
        $dossier = dirname($cheminDuCanal);
        $extension = '.'.pathinfo($cheminDuCanal, PATHINFO_EXTENSION);
        $chemins = is_dir($dossier) && is_readable($dossier)
            ? glob($dossier.'/*-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'.$extension)
            : false;

        if ($chemins === false) {
            throw new RuntimeException('Le dossier des journaux ne se lit pas : '.$dossier);
        }

        $fichiers = array_filter(
            array_filter($chemins, is_file(...)),
            static fn (string $fichier): bool => ! is_link($fichier),
        );

        return array_values(array_filter(
            $fichiers,
            static fn (string $fichier): bool => self::estAuPlusLe(substr(basename($fichier, $extension), -10), $dernierJour),
        ));
    }

    /**
     * Vrai pour une date réelle de ce jour ou d'avant. Toutes deux s'écrivent
     * `AAAA-MM-JJ`, et se comparent donc comme des textes.
     */
    private static function estAuPlusLe(string $date, string $dernierJour): bool
    {
        return checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            && strcmp($date, $dernierJour) <= 0;
    }
}
