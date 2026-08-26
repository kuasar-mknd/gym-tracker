<?php

declare(strict_types=1);

/*
 * Il n'y a qu'UNE famille de variables de couleur, et une valeur ne s'y ecrit
 * qu'une fois.
 *
 * Le depot en portait deux, et elles n'etaient pas la meme chose :
 *
 *  - `--color-text-muted`, declaree dans le bloc `@theme`. C'est celle que
 *    Tailwind lit pour produire `.text-text-muted`, donc celle que le HTML
 *    utilise reellement ;
 *  - `--text-muted`, une variable historique lue par les regles CSS ecrites a
 *    la main.
 *
 * Tant que chacune portait son propre litteral, elles pouvaient diverger — et
 * elles l'ont fait. Sous le mode sombre, seule la seconde etait renversee : les
 * 363 usages de `text-text-muted` gardaient leur gris clair sur fond sombre,
 * mesure au navigateur a 1,96:1 la ou la norme AA demande 4,5:1. Pas « un peu
 * juste » : illisible, et ca expliquait a soi seul l'essentiel du grief de
 * #1317.
 *
 * Ce controle a d'abord exige que la seconde famille REFERENCE la premiere, ce
 * qui rendait l'ecart impossible sans rien supprimer. C'etait la bonne mesure
 * tant que des regles la lisaient encore. Elles n'existent plus : les 22
 * dernieres lectures ont ete converties avec le reste (#1580), et la famille
 * historique a ete retiree.
 *
 * L'invariant est donc plus fort qu'avant, et ce test verifie le nouveau : plus
 * aucun alias, et aucune valeur ecrite deux fois. Le laisser sur l'ancienne
 * formulation aurait exige la presence de ce qu'on venait de supprimer.
 */

it('ne laisse revenir aucune famille d alias parallele', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    /*
     * Les variables sans prefixe `--color-` qui pointent vers un jeton. Une
     * seule suffit a recreer la situation : elle devient le nom court et
     * commode, on l'emploie, puis quelqu'un lui donne une valeur propre « juste
     * pour cet ecran ».
     */
    preg_match_all('/^\s*--(?!color-)([a-z][a-z0-9-]*): var\(--color-[a-z0-9-]+\);/m', $css, $alias);

    expect($alias[1])->toBe([], sprintf(
        "Ces variables sont des alias de jetons :\n  %s\n\n"
        .'La charte porte un seul nom par couleur. Un alias est commode le jour ou on le pose et '
        ."devient une seconde source le jour ou quelqu'un lui donne sa propre valeur — c'est "
        .'exactement ainsi que `--text-muted` et `--color-text-muted` ont diverge.',
        implode("\n  ", $alias[1])
    ));
});

it('n ecrit jamais deux fois la meme couleur dans la charte', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match_all('/^\s*(--color-[a-z0-9-]+): (#[0-9a-fA-F]{6});/m', $css, $declarations, PREG_SET_ORDER);

    $parValeur = [];

    foreach ($declarations as $declaration) {
        $parValeur[strtolower($declaration[2])][] = $declaration[1];
    }

    /*
     * Deux jetons peuvent legitimement partager une valeur quand ils disent
     * deux choses differentes qui se trouvent coincider — `category-chest` vaut
     * l'orange de l'identite parce que les pectoraux sont le groupe phare, pas
     * parce qu'ils SONT l'accent principal. Les separer permet de changer l'un
     * sans l'autre, et c'est le but.
     *
     * Ce qui est interdit, c'est de partager une valeur SANS le dire. La
     * dispense se demande donc explicitement, en nommant la paire ici.
     */
    $coincidences_assumees = [
        ['--color-accent-primary', '--color-category-chest'],
        ['--color-accent-secondary', '--color-category-shoulders'],
        ['--color-accent-tertiary', '--color-category-back'],
        ['--color-accent-info', '--color-category-arms'],
        ['--color-accent-state', '--color-category-legs'],
        ['--color-text-muted', '--color-category-other'],

        /*
         * Le blanc d'une carte et le blanc qu'on ecrit sur un accent sombre
         * sont le meme blanc, et l'encre du texte courant est la meme encre que
         * celle posee sur un accent clair. Deux noms parce que ce sont deux
         * roles : le jour ou les cartes prendront un blanc casse, le texte sur
         * l'orange devra rester franc.
         */
        ['--color-surface-card', '--color-text-on-dark-accent'],
        ['--color-text-main', '--color-text-on-accent'],
    ];

    $fautives = [];

    foreach ($parValeur as $valeur => $jetons) {
        if (count($jetons) < 2) {
            continue;
        }

        sort($jetons);

        foreach ($coincidences_assumees as $assumee) {
            sort($assumee);

            if ($jetons === $assumee) {
                continue 2;
            }
        }

        $fautives[] = sprintf('%s : %s', $valeur, implode(', ', $jetons));
    }

    expect($fautives)->toBe([], sprintf(
        "Ces jetons portent la meme valeur sans que ce soit declare :\n  %s\n\n"
        .'Soit ils disent la meme chose, et il en faut un seul ; soit ils disent deux choses qui '
        ."coincident aujourd'hui, et il faut l'ecrire dans `\$coincidences_assumees` pour que le "
        .'prochain qui en change une sache ce qui bougera avec.',
        implode("\n  ", $fautives)
    ));
});
