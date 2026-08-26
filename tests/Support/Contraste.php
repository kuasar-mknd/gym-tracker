<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Le calcul de contraste de la charte, partage par les gardes qui en dependent.
 *
 * Ces fonctions vivaient dans `LaCharteTientSesContrastesTest`, ou elles ont ete
 * ecrites en premier. Trois controles s'en servent desormais, et un fichier de
 * test n'est pas charge quand un autre s'execute seul : lancer un seul de ces
 * gardes rendait « Call to undefined function », ce qui donne l'impression d'un
 * garde casse alors qu'il ne manque qu'un chargement.
 *
 * Le seuil retenu partout est celui de WCAG 2.1 niveau AA, 4,5:1 pour du texte
 * courant. Les paires purement decoratives — un filet, une pastille sans texte —
 * n'y sont pas soumises : la norme ne les regarde pas.
 */
final class Contraste
{
    /**
     * La luminance relative d'une couleur, selon WCAG 2.1.
     */
    public static function luminance(string $hexa): float
    {
        $hexa = ltrim($hexa, '#');

        $canaux = [];

        foreach ([0, 2, 4] as $decalage) {
            $canal = hexdec(substr($hexa, $decalage, 2)) / 255;
            $canaux[] = $canal <= 0.03928 ? $canal / 12.92 : (($canal + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $canaux[0] + 0.7152 * $canaux[1] + 0.0722 * $canaux[2];
    }

    /**
     * Le rapport de contraste entre deux couleurs pleines.
     */
    public static function entre(string $premier, string $second): float
    {
        $a = self::luminance($premier);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * La valeur d'un jeton, lue dans le bloc `@theme` d'`app.css`.
     *
     * Les alias — ceux qui pointent vers un autre jeton — sont suivis, sinon le
     * controle passerait a cote de toute paire ecrite avec un ancien nom.
     *
     * @throws RuntimeException quand le nom n'est pas un jeton de couleur
     */
    public static function jeton(string $nom): string
    {
        /** @var string|null $css */
        static $css = null;
        $css ??= (string) file_get_contents(resource_path('css/app.css'));

        for ($saut = 0; $saut < 5; $saut++) {
            if (preg_match('/--color-'.preg_quote($nom, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $direct) === 1) {
                return strtolower($direct[1]);
            }

            if (preg_match('/--color-'.preg_quote($nom, '/').':\s*var\(--color-([a-z0-9-]+)\)\s*;/', $css, $alias) !== 1) {
                break;
            }

            $nom = $alias[1];
        }

        throw new RuntimeException("Le jeton `--color-{$nom}` n'est pas declare, ou sa valeur n'est pas une couleur pleine.");
    }
}
