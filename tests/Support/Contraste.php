<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Charte;

/**
 * Le calcul de contraste, pour les gardes de convention.
 *
 * Cette classe portait sa propre implementation de la formule WCAG. Elle
 * delegue desormais a `App\Support\Charte`, qui en a besoin de toute facon pour
 * generer la page de documentation.
 *
 * Deux copies d'une formule sont deux copies de trop — c'est litteralement la
 * lecon que cette charte a coutee, et il aurait ete singulier de la laisser
 * s'appliquer partout sauf aux outils qui la font respecter.
 *
 * Le seuil retenu partout est celui de WCAG 2.1 niveau AA : 4,5:1 pour du texte
 * courant. Les paires purement decoratives — un filet, une pastille sans texte —
 * n'y sont pas soumises : la norme ne les regarde pas.
 */
final class Contraste
{
    /**
     * Le rapport de contraste entre deux couleurs pleines.
     */
    public static function entre(string $premier, string $second): float
    {
        return Charte::contraste($premier, $second);
    }

    /**
     * La valeur d'un jeton, par son nom sans le prefixe `--color-`.
     *
     * @throws \RuntimeException quand le nom n'est pas un jeton de couleur
     */
    public static function jeton(string $nom): string
    {
        $valeur = Charte::jeton($nom);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $valeur) !== 1) {
            throw new \RuntimeException(
                "Le jeton `--color-{$nom}` ne vaut pas une couleur pleine : {$valeur}"
            );
        }

        return strtolower($valeur);
    }
}
