<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * La fermeture du precache, refaite en PHP depuis le manifeste de la
 * construction (`resources/js/sw/fermetureDuPrecache.js`, #2048).
 *
 * Le greffon de Vite la calcule, et `ServiceWorkerAssetsTest` la refait ici
 * sans lui, pour la comparer au precache que le worker a reçu : un greffon qui
 * suivrait mal un import, ou qui suivrait l'entrée jusqu'à chaque page, se
 * verrait. Les listes du module se lisent comme un texte, une entrée par
 * ligne, et une ligne d'une autre forme fait échouer la lecture au lieu d'être
 * sautée, comme `Tests\Support\PagesGardees`.
 */
final class FermetureDuPrecache
{
    /**
     * Le module, depuis la racine du dépôt.
     */
    public const string MODULE = 'resources/js/sw/fermetureDuPrecache.js';

    /**
     * L'entrée de l'application, sous son nom dans le manifeste.
     */
    public const string ENTREE = 'resources/js/main.js';

    /**
     * Le dossier des pages, sous leur nom dans le manifeste.
     */
    public const string DOSSIER_DES_PAGES = 'resources/js/Pages/';

    /**
     * Un composant de graphique, sous son nom dans le manifeste.
     */
    private const string MOTIF_D_UN_GRAPHIQUE = '#^resources/js/Components/Stats/\w+Chart\.vue$#';

    /**
     * Le début de la liste des graphiques dans le module.
     */
    private const string DEBUT_DES_GRAPHIQUES = 'export const GRAPHIQUES_INDISPONIBLES_HORS_LIGNE = Object.freeze([';

    /**
     * La forme d'une entrée de la liste des graphiques.
     */
    private const string FORME_D_UN_GRAPHIQUE = "#^\\s*'(resources/js/Components/Stats/\\w+Chart\\.vue)',?\\s*$#";

    /**
     * Le début de la liste des pages précachées sans copie dans le module.
     */
    private const string DEBUT_DES_PAGES_SANS_COPIE = 'export const PAGES_PRECACHEES_SANS_COPIE = Object.freeze([';

    /**
     * La forme d'une entrée de la liste des pages précachées sans copie.
     */
    private const string FORME_D_UNE_PAGE_SANS_COPIE = "#^\\s*'([A-Z][A-Za-z0-9]*(?:/[A-Z][A-Za-z0-9]*)*)',?\\s*$#";

    /**
     * Les graphiques admis « indisponibles hors ligne »
     * (`GRAPHIQUES_INDISPONIBLES_HORS_LIGNE`), sous leur nom dans le manifeste.
     *
     * @return list<string>
     */
    public static function graphiquesIndisponiblesHorsLigne(): array
    {
        return self::liste(self::DEBUT_DES_GRAPHIQUES, self::FORME_D_UN_GRAPHIQUE, 'GRAPHIQUES_INDISPONIBLES_HORS_LIGNE');
    }

    /**
     * Les pages précachées sans être gardées (`PAGES_PRECACHEES_SANS_COPIE`),
     * par leur composant.
     *
     * @return list<string>
     */
    public static function pagesPrecacheesSansCopie(): array
    {
        return self::liste(self::DEBUT_DES_PAGES_SANS_COPIE, self::FORME_D_UNE_PAGE_SANS_COPIE, 'PAGES_PRECACHEES_SANS_COPIE');
    }

    /**
     * Les points de départ, sous leur nom dans le manifeste : l'entrée, chaque
     * page gardée et chaque page précachée sans copie.
     *
     * @return list<string>
     */
    public static function pointsDeDepart(): array
    {
        $composants = [...array_column(PagesGardees::liste(), 'composant'), ...self::pagesPrecacheesSansCopie()];

        return [self::ENTREE, ...array_values(array_unique(array_map(
            static fn (string $composant): string => self::DOSSIER_DES_PAGES.$composant.'.vue',
            $composants,
        )))];
    }

    /**
     * La fermeture : les fichiers, sous leur chemin dans public/build, que
     * chaque point de départ demande par ses feuilles, ses imports et ses
     * chargements à la demande, sans suivre une page qui n'est pas un point de
     * départ ni un graphique nommé. Les polices restent aux motifs du precache.
     *
     * @param  array<string, mixed>  $manifeste  le contenu de `manifest.json`
     * @return list<string> triés
     */
    public static function fichiers(array $manifeste): array
    {
        $departs = self::pointsDeDepart();
        $graphiques = self::graphiquesIndisponiblesHorsLigne();
        $fichiers = [];
        $vus = [];
        $aSuivre = $departs;

        while ($aSuivre !== []) {
            $nom = (string) array_pop($aSuivre);

            if (isset($vus[$nom])) {
                continue;
            }

            $vus[$nom] = true;
            $entree = $manifeste[$nom] ?? null;

            if (! is_array($entree) || ! is_string($entree['file'] ?? null)) {
                throw new RuntimeException(sprintf('%s est absent du manifeste de la construction.', $nom));
            }

            $fichiers[] = $entree['file'];

            foreach (self::chaines($entree, 'css') as $feuille) {
                $fichiers[] = $feuille;
            }

            foreach (self::chaines($entree, 'assets') as $actif) {
                if (! str_ends_with($actif, '.woff2')) {
                    $fichiers[] = $actif;
                }
            }

            foreach (self::chaines($entree, 'imports') as $importe) {
                $aSuivre[] = $importe;
            }

            foreach (self::chaines($entree, 'dynamicImports') as $charge) {
                $page = str_starts_with($charge, self::DOSSIER_DES_PAGES) && ! in_array($charge, $departs, true);
                $graphique = preg_match(self::MOTIF_D_UN_GRAPHIQUE, $charge) === 1 && in_array($charge, $graphiques, true);

                if (! $page && ! $graphique) {
                    $aSuivre[] = $charge;
                }
            }
        }

        $fichiers = array_values(array_unique($fichiers));
        sort($fichiers);

        return $fichiers;
    }

    /**
     * Une liste de chaînes d'une entrée du manifeste, vide quand elle manque.
     *
     * @param  array<mixed>  $entree
     * @return list<string>
     */
    private static function chaines(array $entree, string $champ): array
    {
        $valeurs = $entree[$champ] ?? [];

        if (! is_array($valeurs)) {
            return [];
        }

        return array_values(array_filter($valeurs, is_string(...)));
    }

    /**
     * Une liste gelée du module, une chaîne par ligne.
     *
     * @return list<string>
     */
    private static function liste(string $debutDeLaListe, string $forme, string $nomDeLaListe): array
    {
        $source = self::source();
        $debut = strpos($source, $debutDeLaListe);
        $fin = $debut === false ? false : strpos($source, '])', $debut);

        if ($debut === false || $fin === false) {
            throw new RuntimeException(sprintf('`%s` est introuvable dans %s : si sa forme a changé, Tests\Support\FermetureDuPrecache doit suivre.', $nomDeLaListe, self::MODULE));
        }

        $corps = substr($source, $debut + strlen($debutDeLaListe), $fin - $debut - strlen($debutDeLaListe));
        $entrees = [];

        foreach (explode("\n", $corps) as $ligne) {
            if (trim($ligne) === '') {
                continue;
            }

            if (preg_match($forme, $ligne, $champs) !== 1) {
                throw new RuntimeException(sprintf("Une ligne de `%s` (%s) ne suit pas la forme d'une entrée par ligne : %s", $nomDeLaListe, self::MODULE, trim($ligne)));
            }

            $entrees[] = $champs[1];
        }

        return $entrees;
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
