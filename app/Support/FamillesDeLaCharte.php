<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Le classement des jetons de la charte en familles.
 *
 * C'est une TAXONOMIE, pas de l'orchestration : elle dit qu'un jeton nommé
 * `accent-state` est un accent vif et qu'un jeton nommé `accent-state-deep` ne
 * l'est pas. Elle vivait dans la commande qui génère la page, où elle occupait
 * plus de place que le travail de la commande elle-même.
 *
 * L'ordre n'est pas alphabétique à dessein : on descend du plus général — ce sur
 * quoi tout se pose — au plus particulier.
 *
 * Un jeton qui n'entre dans aucune famille n'est pas perdu : `classer()` le
 * range sous « Autres ». Une documentation qui tait ce qu'elle ne sait pas
 * classer est pire qu'inutile — c'est ainsi qu'un jeton peut exister pendant des
 * mois sans que personne ne sache à quoi il sert.
 */
final class FamillesDeLaCharte
{
    private const string SURFACES = 'Ce sur quoi tout se pose.';

    private const string TRAITS = "Ce qui délimite, et les deux noirs qui ne sont pas de l'encre.";

    private const string TEXTE = "Ce qu'on écrit, et sur quoi.";

    private const string ACCENTS = "L'identité. Fonds, icônes, anneaux, lueurs — jamais les mots.";

    private const string DEEP = "Les mêmes, calculées contre le pire fond qu'elles rencontrent : un lavis "
        ."de leur propre accent à 20 %. Elles n'existent que pour porter du texte.";

    private const string FILL = 'Lues uniquement par les utilitaires appariés, jamais par un composant.';

    private const string TENDANCE = 'Une direction, pas un jugement.';

    private const string SESSION = 'Le bandeau du tableau de bord, et lui seul.';

    private const string CATEGORIES = 'Huit groupes musculaires. Aucune intention — seulement se '
        .'distinguer, ce qui se mesure en CIELAB.';

    private const string PALETTE = 'Seize teintes pour les habitudes. Chacune porte du blanc, par construction.';

    private const string PLATES = 'Le code olympique. Une norme extérieure, pas un choix de charte.';

    private const string AUTRES = "Aucune famille ne les réclame — c'est en soi une information.";

    /**
     * Range chaque jeton dans sa famille, en conservant l'ordre de lecture.
     *
     * @param  array<string, string>  $jetons
     * @return list<array{titre: string, sous: string, jetons: array<string, string>}>
     */
    public static function classer(array $jetons): array
    {
        $restants = $jetons;
        $familles = [];

        foreach (self::definitions() as $titre => [$sous, $appartient]) {
            $membres = array_filter($restants, $appartient, ARRAY_FILTER_USE_KEY);

            if ($membres === []) {
                continue;
            }

            $restants = array_diff_key($restants, $membres);
            $familles[] = ['titre' => $titre, 'sous' => $sous, 'jetons' => $membres];
        }

        if ($restants !== []) {
            $familles[] = ['titre' => 'Autres', 'sous' => self::AUTRES, 'jetons' => $restants];
        }

        return $familles;
    }

    /**
     * @return array<string, array{0: string, 1: callable(string): bool}>
     */
    private static function definitions(): array
    {
        return [
            'Surfaces' => [self::SURFACES, self::commence('surface-')],
            'Traits et ombres' => [self::TRAITS, self::commenceParUn(['border', 'shadow-'])],
            'Texte' => [self::TEXTE, self::commence('text-')],
            'Accents vifs' => [self::ACCENTS, self::accentVif()],
            'Variantes lisibles' => [self::DEEP, self::finit('-deep')],
            'Surfaces pleines' => [self::FILL, self::finit('-fill')],
            'Tendance' => [self::TENDANCE, self::commence('trend-')],
            'Séance en cours' => [self::SESSION, self::commence('session-')],
            'Catégories' => [self::CATEGORIES, self::commence('category-')],
            'Palette utilisateur' => [self::PALETTE, self::commence('palette-')],
            'Disques de fonte' => [self::PLATES, self::commence('plate-')],
        ];
    }

    /**
     * @return callable(string): bool
     */
    private static function commence(string $debut): callable
    {
        return static fn (string $nom): bool => str_starts_with($nom, $debut);
    }

    /**
     * @param  list<string>  $debuts
     * @return callable(string): bool
     */
    private static function commenceParUn(array $debuts): callable
    {
        return static fn (string $nom): bool => array_any($debuts, fn (string $debut): bool => str_starts_with($nom, $debut));
    }

    /**
     * @return callable(string): bool
     */
    private static function finit(string $fin): callable
    {
        return static fn (string $nom): bool => str_ends_with($nom, $fin);
    }

    /**
     * Un accent VIF : ni sa variante lisible, ni sa surface pleine.
     *
     * Les trois portent le même préfixe et ne disent pas la même chose. Les
     * confondre remettrait dans la même case l'orange de l'identité et l'orange
     * assombri qui existe uniquement pour porter du texte.
     *
     * @return callable(string): bool
     */
    private static function accentVif(): callable
    {
        return static fn (string $nom): bool => str_starts_with($nom, 'accent-')
            && ! str_ends_with($nom, '-deep')
            && ! str_ends_with($nom, '-fill');
    }
}
