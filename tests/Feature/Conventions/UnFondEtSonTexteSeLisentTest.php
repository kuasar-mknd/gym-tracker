<?php

declare(strict_types=1);

/*
 * Quand un element porte un fond ET un texte, les deux doivent se lire.
 *
 * C'est le dernier trou de la charte, et il etait large. Les deux autres
 * controles regardent chacun une moitie du probleme :
 *
 *  - `LaCharteTientSesContrastesTest` verifie les paires que la charte DECLARE.
 *    Il ne sait rien de ce que les composants ecrivent reellement ;
 *  - `UnJetonNeSecritPasEnTexteSilEstIllisibleTest` verifie un texte sur les
 *    surfaces de l'application, et exclut donc les jetons faits pour un fond
 *    sombre — sans quoi `text-surface-card`, qui est du blanc, serait signale a
 *    1:1 sur une carte blanche.
 *
 * Entre les deux passait le cas le plus courant : `bg-accent-info
 * text-text-on-dark-accent`, du blanc sur le cyan vif, a 1,54:1. Une icone
 * invisible sur son propre bouton, et aucun des deux controles ne pouvait la
 * voir — le premier parce que la charte ne declare pas cette paire, le second
 * parce qu'il ecarte precisement ce jeton de texte.
 *
 * Ce controle lit les deux classes ENSEMBLE, la ou elles sont ecrites. C'est la
 * seule facon de connaitre le fond : un nom de classe de texte ne dit rien de
 * ce qu'il y a dessous.
 *
 * Le vrai remede reste les utilitaires apparies — `accent-fill`, `info-fill`,
 * `category-fill-*` — qui posent le fond et son texte d'un seul geste. Ce test
 * est ce qui signale les endroits qui ne les emploient pas encore.
 */

use Symfony\Component\Finder\Finder;
use Tests\Support\Contraste;

/**
 * Les couples fond/texte ecrits dans un meme attribut de classe.
 *
 * @return list<array{fond: string, texte: string, fichier: string}>
 */
function couplesFondEtTexte(): array
{
    $fichiers = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name('*.vue');

    $couples = [];

    foreach ($fichiers as $fichier) {
        preg_match_all('/(?::)?class="([^"]*)"/s', $fichier->getContents(), $attributs);

        foreach ($attributs[1] as $attribut) {
            /*
             * On decoupe sur les separateurs d'une liaison dynamique. Deux
             * branches d'un ternaire ne sont jamais appliquees ensemble : les
             * lire comme un seul lot inventerait des couples qui n'existent pas.
             */
            foreach (preg_split("/['\"]|\?|:(?![a-z-])|,/", $attribut) as $morceau) {
                preg_match_all('/\bbg-([a-z][a-z0-9-]*)\b(?!\/)/', (string) $morceau, $fonds);
                preg_match_all('/\btext-([a-z][a-z0-9-]*)\b(?!\/)/', (string) $morceau, $textes);

                foreach ($fonds[1] as $fond) {
                    foreach ($textes[1] as $texte) {
                        $couples[] = [
                            'fond' => $fond,
                            'texte' => $texte,
                            'fichier' => $fichier->getRelativePathname(),
                        ];
                    }
                }
            }
        }
    }

    return $couples;
}

it('ne pose jamais un texte illisible sur son propre fond', function (): void {
    $illisibles = [];
    $vus = [];

    foreach (couplesFondEtTexte() as $couple) {
        try {
            $devant = Contraste::jeton($couple['texte']);
            $derriere = Contraste::jeton($couple['fond']);
        } catch (RuntimeException) {
            // `text-xs`, `bg-linear-to-br` : ni l'un ni l'autre n'est une couleur.
            continue;
        }

        $mesure = Contraste::entre($devant, $derriere);

        if ($mesure >= 4.5) {
            continue;
        }

        $cle = $couple['fond'].'|'.$couple['texte'];

        if (array_key_exists($cle, $vus)) {
            continue;
        }

        $vus[$cle] = true;
        $illisibles[] = sprintf(
            'text-%s sur bg-%s : %.2f:1 (%s)',
            $couple['texte'],
            $couple['fond'],
            $mesure,
            $couple['fichier']
        );
    }

    expect($illisibles)->toBe([], sprintf(
        "Ces couples fond/texte ne se lisent pas :\n  %s\n\n"
        ."Plutot que de corriger la couleur du texte a la main, employez l'utilitaire apparie qui "
        .'correspond — `accent-fill`, `state-fill`, `info-fill`, `danger-fill`, `category-fill-*`, '
        .'`plate-fill-*`. Ils posent le fond ET son texte, et leur valeur est calculee dans la '
        ."charte.\n\n"
        .'Choisir soi-meme le texte est precisement ce qui a produit un haltere noir sur une tuile '
        .'orange et une icone blanche sur du cyan vif.',
        implode("\n  ", $illisibles)
    ));
});
