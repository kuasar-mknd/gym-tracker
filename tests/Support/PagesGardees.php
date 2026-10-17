<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * La liste blanche des pages gardées pour la salle, telle que la page la lit
 * (`resources/js/Utils/pagesGardees.js`, #2048).
 *
 * Les tests PHP n'exécutent pas de JavaScript : ils lisent le module comme un
 * texte, sous la forme qu'il annonce dans son docbloc, une entrée par ligne.
 * Une ligne de la liste qui ne suit pas cette forme fait échouer la lecture,
 * au lieu d'être sautée : une entrée écrite autrement échapperait sinon à
 * chaque garde qui se sert de cette liste.
 */
final class PagesGardees
{
    /**
     * Le module, depuis la racine du dépôt.
     */
    public const string MODULE = 'resources/js/Utils/pagesGardees.js';

    /**
     * Le début de la liste dans le module.
     */
    private const string DEBUT_DE_LA_LISTE = 'export const PAGES_GARDEES = geler([';

    /**
     * La forme d'une entrée.
     */
    private const string FORME_D_UNE_ENTREE = "/^\\s*\\{ composant: '([^']+)', route: '([^']+)', chemin: '([^']+)' \\},?\\s*$/";

    /**
     * Le début des props qu'aucune copie ne garde.
     */
    private const string DEBUT_DES_PROPS_JAMAIS_GARDEES = 'export const PROPS_JAMAIS_GARDEES = Object.freeze({';

    /**
     * La forme d'une entrée des props qu'aucune copie ne garde : le composant,
     * nu ou entre apostrophes, puis la liste gelée de ses props.
     */
    private const string FORME_DES_PROPS_JAMAIS_GARDEES = "/^\\s*(?:'([A-Za-z][A-Za-z0-9\\/]*)'|([A-Za-z][A-Za-z0-9]*)): Object\\.freeze\\(\\[((?:'[A-Za-z_][A-Za-z0-9_]*'(?:, )?)+)\\]\\),?\\s*$/";

    /**
     * Les entrées de la liste blanche, dans l'ordre du module.
     *
     * @return list<array{composant: string, route: string, chemin: string}>
     */
    public static function liste(): array
    {
        $source = self::source();
        $debut = strpos($source, self::DEBUT_DE_LA_LISTE);
        $fin = $debut === false ? false : strpos($source, '])', $debut);

        if ($debut === false || $fin === false) {
            throw new RuntimeException(sprintf('La liste `PAGES_GARDEES` est introuvable dans %s : si sa forme a changé, Tests\Support\PagesGardees doit suivre.', self::MODULE));
        }

        $corps = substr($source, $debut + strlen(self::DEBUT_DE_LA_LISTE), $fin - $debut - strlen(self::DEBUT_DE_LA_LISTE));
        $entrees = [];

        foreach (explode("\n", $corps) as $ligne) {
            if (trim($ligne) === '') {
                continue;
            }

            if (preg_match(self::FORME_D_UNE_ENTREE, $ligne, $champs) !== 1) {
                throw new RuntimeException(sprintf("Une ligne de `PAGES_GARDEES` (%s) ne suit pas la forme `{ composant: '…', route: '…', chemin: '…' }` : %s", self::MODULE, trim($ligne)));
            }

            $entrees[] = ['composant' => $champs[1], 'route' => $champs[2], 'chemin' => $champs[3]];
        }

        if ($entrees === []) {
            throw new RuntimeException(sprintf('La liste `PAGES_GARDEES` de %s est vide.', self::MODULE));
        }

        return $entrees;
    }

    /**
     * Les props qu'aucune copie ne garde, par composant (`PROPS_JAMAIS_GARDEES`) :
     * une entrée par ligne, `Composant: Object.freeze(['prop', …]),`, le
     * composant entre apostrophes quand il porte une barre oblique. Toute autre
     * forme fait échouer la lecture.
     *
     * @return array<string, list<string>>
     */
    public static function propsJamaisGardees(): array
    {
        $source = self::source();
        $debut = strpos($source, self::DEBUT_DES_PROPS_JAMAIS_GARDEES);
        $fin = $debut === false ? false : strpos($source, "\n})", $debut);

        if ($debut === false || $fin === false) {
            throw new RuntimeException(sprintf('`PROPS_JAMAIS_GARDEES` est introuvable dans %s : si sa forme a changé, Tests\Support\PagesGardees doit suivre.', self::MODULE));
        }

        $corps = substr($source, $debut + strlen(self::DEBUT_DES_PROPS_JAMAIS_GARDEES), $fin - $debut - strlen(self::DEBUT_DES_PROPS_JAMAIS_GARDEES));
        $props = [];

        foreach (explode("\n", $corps) as $ligne) {
            if (trim($ligne) === '') {
                continue;
            }

            if (preg_match(self::FORME_DES_PROPS_JAMAIS_GARDEES, $ligne, $champs) !== 1) {
                throw new RuntimeException(sprintf("Une ligne de `PROPS_JAMAIS_GARDEES` (%s) ne suit pas la forme `Composant: Object.freeze(['prop', …]),` : %s", self::MODULE, trim($ligne)));
            }

            $composant = $champs[1] !== '' ? $champs[1] : $champs[2];
            preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'/", $champs[3], $noms);
            $props[$composant] = $noms[1];
        }

        return $props;
    }

    /**
     * La version du contrat des copies (`VERSION_DU_CONTRAT`).
     */
    public static function versionDuContrat(): int
    {
        if (preg_match('/^export const VERSION_DU_CONTRAT = ([1-9]\d*)$/m', self::source(), $version) !== 1) {
            throw new RuntimeException(sprintf('`VERSION_DU_CONTRAT` est introuvable dans %s, ou n’est pas un entier positif.', self::MODULE));
        }

        return (int) $version[1];
    }

    /**
     * Le texte du module.
     */
    private static function source(): string
    {
        $chemin = dirname(__DIR__, 2).'/'.self::MODULE;
        $source = is_file($chemin) ? file_get_contents($chemin) : false;

        if ($source === false) {
            throw new RuntimeException(sprintf('%s est introuvable.', self::MODULE));
        }

        return $source;
    }
}
