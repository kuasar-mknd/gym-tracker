<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Ce que copie le bouton « Copier la structure » de la page « Aperçu du
 * diagnostic » (#2032), lu dans le HTML rendu : le texte que son expression
 * Alpine passe au presse-papiers, décodé.
 */
final class BoutonCopierLaStructure
{
    /**
     * @return array<array-key, mixed>
     */
    public static function structureCopiee(string $html): array
    {
        if (preg_match('/<button\b[^>]*\bdata-structure-a-copier\b[^>]*>/s', $html, $bouton) !== 1) {
            Assert::fail('Le bouton « Copier la structure » est absent de la page.');
        }

        $attributs = html_entity_decode($bouton[0], ENT_QUOTES | ENT_HTML5);

        if (preg_match("/clipboard\\.writeText\\('(?<texte>[^']*)'\\)/", $attributs, $copie) !== 1) {
            Assert::fail('Le bouton ne copie pas de texte : '.$attributs);
        }

        $json = json_decode('"'.$copie['texte'].'"');
        $structure = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($structure)) {
            Assert::fail('Le texte copié n’est pas du JSON : '.$copie['texte']);
        }

        return $structure;
    }
}
