<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Les expressions de l'expurgation, chacune vérifiée après son appel
 * (`preg_last_error()`) : une expression qui échoue rend `null`, et un
 * remplacement qui rendrait le texte d'origine laisserait passer tout ce
 * qu'il devait masquer. L'échec lève `TexteIllisible`, que `ExpurgeLeTexte`
 * change en `[illisible]` pour le champ entier.
 *
 * Sans état : rien n'est gardé d'un appel à l'autre, ni sous Octane.
 */
final class Motifs
{
    public static function remplacer(string $motif, string $remplacement, string $texte): string
    {
        return self::verifier(preg_replace($motif, $remplacement, $texte));
    }

    /**
     * Les morceaux de la première occurrence du motif, `null` s'il n'y en a
     * pas.
     *
     * @return array<array-key, string>|null
     */
    public static function trouver(string $motif, string $texte): ?array
    {
        $trouve = preg_match($motif, $texte, $morceaux);
        self::verifier(match ($trouve) {
            false => null,
            default => '',
        });

        return match ($trouve) {
            1 => $morceaux,
            default => null,
        };
    }

    /**
     * Toutes les occurrences du motif, chacune avec ses morceaux.
     *
     * @return list<array<array-key, string>>
     */
    public static function toutes(string $motif, string $texte): array
    {
        $trouve = preg_match_all($motif, $texte, $occurrences, PREG_SET_ORDER);
        self::verifier(match ($trouve) {
            false => null,
            default => '',
        });

        return $occurrences;
    }

    /**
     * Lève si la dernière expression appelée, ailleurs qu'ici, a échoué.
     */
    public static function verifierLeDernierAppel(): void
    {
        self::verifier('');
    }

    private static function verifier(?string $resultat): string
    {
        if ($resultat === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new TexteIllisible(preg_last_error_msg());
        }

        return $resultat;
    }
}
