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
 *
 * CE QU'IL NE VOIT PAS, et il vaut mieux l'ecrire que le laisser supposer :
 *
 * Un utilitaire applique DYNAMIQUEMENT. Le calculateur de disques lie
 * `:class="[getPlateColor(poids)]"` — la chaine `plate-fill-15` n'apparait nulle
 * part dans le gabarit, elle sort d'une fonction du script. Aucune lecture
 * statique ne peut relier la valeur de retour d'une fonction a l'element qui la
 * porte, ni savoir quels enfants cet element aura.
 *
 * C'est exactement sous cette forme que le defaut le plus grave de la conversion
 * a survecu : le disque JAUNE de 15 kg ecrivait son poids en BLANC, a 1,65:1,
 * parce qu'un `<div>` enfant posait sa propre couleur par-dessus celle que
 * `plate-fill-15` avait calculee. Il a ete trouve par une relecture, pas par ce
 * test.
 *
 * Une regle au niveau du fichier — « ce composant applique un `-fill`
 * dynamiquement ET pose une couleur de texte » — a ete essayee : elle signale
 * deux composants, et les deux sont corrects. Un garde qui se declenche sur du
 * code juste finit desactive, ce qui est pire que pas de garde.
 *
 * La couverture de ce cas demande une mesure sur le rendu, pas sur la source.
 * C'est le role d'un test de navigateur, et le depot en a une suite (Dusk).
 */

use Symfony\Component\Finder\Finder;
use Tests\Support\Contraste;

/**
 * Une couleur posee a `$opacite` par-dessus une autre.
 *
 * Un `bg-accent-primary/10` n'est PAS l'orange : c'est un orange tres dilue sur
 * la surface d'en dessous. Mesurer le contraste contre le jeton pur donne un
 * chiffre qui n'existe nulle part a l'ecran — et rassurant, ce qui est pire.
 */
function surLavis(string $devant, string $fond, float $opacite): string
{
    $devant = ltrim($devant, '#');
    $fond = ltrim($fond, '#');

    $canaux = [];

    foreach ([0, 2, 4] as $decalage) {
        $canaux[] = (int) round(
            hexdec(substr($devant, $decalage, 2)) * $opacite
            + hexdec(substr($fond, $decalage, 2)) * (1 - $opacite)
        );
    }

    return sprintf('#%02x%02x%02x', $canaux[0], $canaux[1], $canaux[2]);
}

/**
 * Les classes d'un morceau, rangees par VARIANTE.
 *
 * `text-text-muted hover:bg-accent-danger/20 hover:text-accent-danger-deep` ne
 * pose jamais le gris sur le lavis : au repos il n'y a pas de lavis, et au
 * survol le texte change en meme temps que le fond. Les lire ensemble signalait
 * sept fautes qui n'existent pas.
 *
 * Une variante hérite du repos — un `hover:bg-x` s'applique avec le
 * `text-y` de base tant qu'aucun `hover:text-*` ne le remplace — donc chaque
 * groupe est constitue du repos PUIS de ses propres classes.
 *
 * @return array<string, list<string>>
 */
function parVariante(string $morceau): array
{
    $groupes = ['' => []];

    $decoupees = preg_split('/\s+/', trim($morceau));

    foreach ($decoupees === false ? [] : $decoupees as $classe) {
        if ($classe === '') {
            continue;
        }

        $variante = '';

        if (preg_match('/^([a-z-]+(?::[a-z-]+)*):(?=(?:bg|text)-)/', $classe, $prefixe) === 1) {
            $variante = $prefixe[1];
            $classe = substr($classe, strlen($variante) + 1);
        }

        $groupes[$variante] ??= [];
        $groupes[$variante][] = $classe;
    }

    $repos = $groupes[''];

    foreach ($groupes as $variante => $classes) {
        if ($variante !== '') {
            $groupes[$variante] = array_merge($repos, $classes);
        }
    }

    return $groupes;
}

/**
 * Les morceaux d'un attribut de classe qui peuvent s'appliquer ENSEMBLE.
 *
 * Deux branches d'un ternaire ne le sont jamais : les lire comme un seul lot
 * inventerait des couples qui n'existent pas. Le premier controle de ce fichier
 * l'a toujours su ; le second, ajoute plus tard, l'avait oublie et signalait six
 * conflits dont aucun n'existait — un garde qui crie a tort finit desactive.
 *
 * @return list<string>
 */
function branchesDe(string $attribut): array
{
    // `preg_split` rend `false` sur motif invalide : le repli vide vaut mieux
    // qu'un `foreach` sur un booleen.
    $morceaux = preg_split("/['\"]|\?|:(?![a-z-])|,/", $attribut);

    return $morceaux === false ? [] : $morceaux;
}

/**
 * Les couples fond/texte ecrits dans un meme attribut de classe.
 *
 * @return list<array{fond: string, opacite: float, texte: string, fichier: string}>
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
            foreach (branchesDe($attribut) as $morceau) {
                foreach (parVariante((string) $morceau) as $classes) {
                    $ensemble = implode(' ', $classes);

                    // Le `/N` est capture : un lavis n'est pas son jeton.
                    preg_match_all('/\bbg-([a-z][a-z0-9-]*)(?:\/(\d+))?\b/', $ensemble, $fonds, PREG_SET_ORDER);
                    preg_match_all('/\btext-([a-z][a-z0-9-]*)\b(?!\/)/', $ensemble, $textes);

                    /*
                     * Une seule couleur s'applique : la DERNIERE. Le repos pose
                     * `text-text-muted`, le survol pose `hover:text-…-deep`
                     * par-dessus — les compter tous les deux signalait sept
                     * fautes qui n'existent pas, puisque le gris n'est jamais
                     * sur le lavis.
                     */
                    $dernierFond = $fonds === [] ? null : $fonds[count($fonds) - 1];
                    $derniersTextes = $textes[1];
                    $dernierTexte = $derniersTextes === [] ? null : $derniersTextes[count($derniersTextes) - 1];

                    if ($dernierFond === null || $dernierTexte === null) {
                        continue;
                    }

                    foreach ([$dernierFond] as $fond) {
                        foreach ([$dernierTexte] as $texte) {
                            $couples[] = [
                                'fond' => $fond[1],
                                'opacite' => isset($fond[2]) ? (int) $fond[2] / 100 : 1.0,
                                'texte' => $texte,
                                'fichier' => $fichier->getRelativePathname(),
                            ];
                        }
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

        if ($couple['opacite'] < 1.0) {
            $derriere = surLavis($derriere, Contraste::jeton('surface-card'), $couple['opacite']);
        }

        $mesure = Contraste::entre($devant, $derriere);

        if ($mesure >= 4.5) {
            continue;
        }

        $cle = $couple['fond'].'|'.$couple['opacite'].'|'.$couple['texte'];

        if (array_key_exists($cle, $vus)) {
            continue;
        }

        $vus[$cle] = true;
        $illisibles[] = sprintf(
            'text-%s sur bg-%s%s : %.2f:1 (%s)',
            $couple['texte'],
            $couple['fond'],
            $couple['opacite'] < 1.0 ? sprintf('/%d', (int) round($couple['opacite'] * 100)) : '',
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

/**
 * Les sous-arbres d'un gabarit qui portent un utilitaire apparie.
 *
 * Rend, pour chacun, le nom de l'utilitaire, le texte du sous-arbre et sa ligne.
 * Le sous-arbre s'arrete a la premiere ligne non vide dont l'indentation est
 * inferieure ou egale a celle de l'element porteur.
 *
 * @param  list<string>  $apparies
 * @return list<array{0: string, 1: string, 2: int}>
 */
function sousArbresApparies(string $contenu, array $apparies): array
{
    $lignes = explode("\n", $contenu);
    $trouves = [];

    foreach ($lignes as $index => $ligne) {
        foreach ($apparies as $apparie) {
            if (preg_match('/\\b'.preg_quote($apparie, '/').'\\b/', $ligne) !== 1) {
                continue;
            }

            $indentation = strlen($ligne) - strlen(ltrim($ligne));
            $morceaux = [];

            for ($suivante = $index + 1; $suivante < count($lignes); $suivante++) {
                $courante = $lignes[$suivante];

                if (trim($courante) === '') {
                    continue;
                }

                if (strlen($courante) - strlen(ltrim($courante)) <= $indentation) {
                    break;
                }

                $morceaux[] = $courante;
            }

            $trouves[] = [$apparie, implode("\n", $morceaux), $index + 1];
        }
    }

    return $trouves;
}

/**
 * Les utilitaires apparies declares par la charte.
 *
 * @return list<string>
 */
function utilitairesApparies(): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match_all(
        '/@utility ([a-z][a-z0-9-]*) \{\s*background: var\(--color-[a-z0-9-]+\);\s*color: var\(--color-[a-z0-9-]+\);/',
        $css,
        $trouves
    );

    return array_values(array_unique($trouves[1]));
}

it('ne recolle jamais un texte par-dessus un utilitaire apparie', function (): void {
    /*
     * Un utilitaire apparie pose le fond ET son texte. Y ajouter un `text-*` sur
     * le meme element annule la moitie de ce qu'il apporte — et pas de facon
     * visible : les deux classes ont la meme specificite, donc c'est l'ordre du
     * CSS genere qui tranche, que personne n'ecrit.
     *
     * C'est arrive deux fois. Le calculateur de barre decidait de la couleur du
     * fond dans `getPlateColor()` et de celle du texte dans un ternaire separe,
     * avec sa propre liste de poids a tenir en accord a la main ; il a ete
     * corrige. La grille d'inventaire, juste en dessous, faisait la meme chose
     * et a ete oubliee : le disque JAUNE de 15 kg y ecrivait son poids en BLANC,
     * a 1,65:1, quand l'encre que `plate-fill-15` posait rendait 10,85:1.
     *
     * Deux endroits decidaient de la meme chose, un seul a ete repris. C'est
     * exactement ce qu'un garde doit rendre impossible.
     */
    $apparies = utilitairesApparies();

    expect($apparies)->not->toBeEmpty('aucun utilitaire apparie trouve : le controle ne prouverait rien');

    $fichiers = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name('*.vue');

    $conflits = [];

    foreach ($fichiers as $fichier) {
        $contenu = $fichier->getContents();

        /*
         * Le conflit se produit aussi entre un PARENT et son enfant, et c'est
         * meme la forme qu'il avait dans le calculateur de disques : l'element
         * portait `plate-fill-15`, et le `<div>` interieur qui affiche le poids
         * ecrivait sa propre couleur. Un controle limite a un seul attribut ne
         * pouvait pas le voir.
         *
         * On approxime le sous-arbre par l'INDENTATION : depuis la ligne qui
         * porte l'utilitaire, on descend tant que les lignes sont plus
         * indentees. C'est grossier et suffisant pour un gabarit Vue, qui est
         * formate par Prettier donc indente regulierement.
         */
        foreach (sousArbresApparies($contenu, $apparies) as [$apparie, $sousArbre, $ligne]) {
            foreach (branchesDe($sousArbre) as $branche) {
                if (preg_match_all('/\\btext-([a-z][a-z0-9-]*)\\b/', $branche, $textes) === 0) {
                    continue;
                }

                foreach ($textes[1] as $texte) {
                    try {
                        Contraste::jeton($texte);
                    } catch (RuntimeException) {
                        continue;
                    }

                    $conflits[] = sprintf(
                        '%s:%d : `%s` et `text-%s` dans le meme sous-arbre',
                        $fichier->getRelativePathname(),
                        $ligne,
                        $apparie,
                        $texte
                    );
                }
            }
        }

        preg_match_all('/(?::)?class="([^"]*)"/s', $contenu, $attributs);

        foreach ($attributs[1] as $attribut) {
            foreach (branchesDe($attribut) as $branche) {
                foreach ($apparies as $apparie) {
                    if (preg_match('/\b'.preg_quote($apparie, '/').'\b/', $branche) !== 1) {
                        continue;
                    }

                    if (preg_match_all('/\btext-([a-z][a-z0-9-]*)\b/', $branche, $textes) === 0) {
                        continue;
                    }

                    foreach ($textes[1] as $texte) {
                        // `text-xs`, `text-center` : ni l'un ni l'autre n'est une couleur.
                        try {
                            Contraste::jeton($texte);
                        } catch (RuntimeException) {
                            continue;
                        }

                        $conflits[] = sprintf(
                            '%s : `%s` et `text-%s` sur le meme element (%s)',
                            $fichier->getRelativePathname(),
                            $apparie,
                            $texte,
                            mb_substr(trim(preg_replace('/\s+/', ' ', $branche) ?? ''), 0, 70)
                        );
                    }
                }
            }
        }
    }

    expect(array_values(array_unique($conflits)))->toBe([], sprintf(
        "Un texte est recolle par-dessus un utilitaire apparie :\n  %s\n\n"
        ."L'utilitaire pose deja le texte, et sa valeur est CALCULEE dans la charte. La classe "
        ."ajoutee l'annule ou non selon l'ordre du CSS genere — donc au hasard. Retirez-la.",
        implode("\n  ", array_unique($conflits))
    ));
});
