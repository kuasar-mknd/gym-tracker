<?php

declare(strict_types=1);

/*
 * Les paires que la charte declare lisibles doivent l'etre, et ca se calcule.
 *
 * L'oeil ne suffit pas — c'est la lecon la plus chere de ce depot. Le texte
 * secondaire est reste a 1,96:1 sur fond sombre pendant des mois : la page
 * s'affichait, les tests passaient, personne ne voyait rien. Il a fallu mesurer
 * au navigateur pour comprendre que la moitie du grief sur le mode sombre tenait
 * a cette seule variable (#1317).
 *
 * Trois choses que ce controle attrape et qu'une relecture ne peut pas :
 *
 *  - eclaircir `--color-text-muted` d'un cran. Il est a 4,56:1 sur le fond de
 *    page, soit six centiemes au-dessus du seuil. Personne ne le devinerait ;
 *  - reprendre du blanc sur un accent. `#ff5500` rend 3,21:1 avec du blanc et
 *    5,57:1 avec de l'encre, ce qui est exactement pourquoi la charte porte
 *    DEUX oranges ;
 *  - poser le vert d'etat en couleur de texte. Il vaut 15,19:1 en fond avec de
 *    l'encre et 1,18:1 en texte sur une carte blanche.
 *
 * Le seuil est celui de WCAG 2.1 niveau AA : 4,5:1 pour du texte courant. Les
 * paires purement decoratives — un filet, une pastille — n'y figurent pas :
 * elles ne portent aucun texte, donc la norme ne les regarde pas.
 */

/**
 * Les paires que la charte promet lisibles.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function pairesDeLaCharte(): array
{
    return [
        ['text-main', 'surface-page', 'texte principal sur le fond de page'],
        ['text-main', 'surface-card', 'texte principal sur une carte'],
        ['text-main', 'surface-sunken', 'texte principal sur une surface creuse'],
        ['text-muted', 'surface-page', 'texte secondaire sur le fond de page'],
        ['text-muted', 'surface-card', 'texte secondaire sur une carte'],
        ['text-on-accent', 'accent-state', "encre sur le vert d'etat"],
        ['text-on-accent', 'accent-info', "encre sur l'information"],
        ['text-on-accent', 'accent-warning', "encre sur l'alerte"],
        ['text-on-accent', 'accent-state', "encre sur l'action principale, qui porte ce vert"],
        ['trend-up', 'surface-card', 'une hausse, ecrite sur une carte'],
        ['trend-down', 'surface-card', 'une baisse, ecrite sur une carte'],
        ['accent-danger-deep', 'surface-card', 'un libelle de danger sur une carte'],
        /*
         * `accent-primary` n'est PAS dans cette liste, et c'est une decision.
         *
         * Vif, il rend 3,07:1 en texte sur le fond de page — sous le seuil. Il
         * y sert pourtant a des libelles decoratifs en capitales (« APERCU »),
         * ou sa vivacite est tout l'interet et ou le mot est repete par le titre
         * juste en dessous. Le rendre conforme demanderait de l'assombrir
         * partout, y compris sur les 183 emplois ou il ne porte aucun texte.
         *
         * C'est la seule exception de la charte, et elle est ecrite ici plutot
         * que subie ailleurs. `accent-primary-fill`, lui, est tenu.
         */
        ['accent-primary-fill', 'surface-card', "l'accent principal en surface pleine"],
    ];
}

it('tient les contrastes que la charte annonce', function (): void {
    $manques = [];

    foreach (pairesDeLaCharte() as [$devant, $derriere, $intitule]) {
        $mesure = contraste(valeurDuJeton($devant), valeurDuJeton($derriere));

        if ($mesure < 4.5) {
            $manques[] = sprintf('%s : %.2f:1 (il en faut 4,5)', $intitule, $mesure);
        }
    }

    expect($manques)->toBe([], sprintf(
        "Ces paires ne sont plus lisibles :\n  %s\n\n"
        .'La norme AA demande 4,5:1 pour du texte courant. Un ecart de quelques centiemes ne se voit '
        ."pas a l'oeil et rend pourtant le texte penible ; c'est ainsi que le texte secondaire est "
        .'reste a 1,96:1 pendant des mois.',
        implode("\n  ", $manques)
    ));
});

it('garde un orange lisible pour les fonds et un autre pour les mots', function (): void {
    /*
     * TROIS oranges, et c'est une decision, pas un oubli.
     *
     * Ce test a d'abord exige l'inverse — un seul orange, portant du blanc —
     * parce qu'en porter deux obligeait chaque composant a choisir entre eux. Le
     * raisonnement etait juste, la conclusion fausse : ce n'est pas le NOMBRE de
     * valeurs qui remettait la decision au composant, c'est qu'aucune des deux
     * ne disait ou elle allait.
     *
     * Les trois ont maintenant chacune leur lecteur unique, et aucun composant
     * ne les rencontre :
     *
     *  - `accent-primary`, le vif, pour tout ce qui n'est pas du texte — icones,
     *    bordures, anneaux, lueurs, degrades. C'est l'identite de l'application,
     *    et c'est la grande majorite de ses emplois ;
     *  - `accent-primary-fill`, lu SEULEMENT par `@utility accent-fill`, qui pose
     *    le fond et son texte blanc d'un seul geste ;
     *  - `accent-primary-deep`, pour l'interieur des lettres, ou la difference ne
     *    se voit pas et ou elle decide de la lisibilite.
     */
    $enFond = contraste('#ffffff', valeurDuJeton('accent-primary-fill'));
    $enTexte = contraste(valeurDuJeton('accent-primary-deep'), valeurDuJeton('surface-sunken'));

    expect($enFond)->toBeGreaterThanOrEqual(4.5, sprintf(
        "L'orange de remplissage rend %.2f:1 avec du blanc, et le blanc est le seul texte que "
        ."`accent-fill` ecrit dessus. Sam a demande deux fois de l'orange AVEC DU BLANC ; c'est ce "
        .'jeton qui tient cette promesse.',
        $enFond
    ));

    expect($enTexte)->toBeGreaterThanOrEqual(4.5, sprintf(
        "L'orange de texte rend %.2f:1 sur la surface la moins favorable. Le vif y rend 2,93:1, ce "
        .'qui rendait illisibles les libelles de section dans 35 fichiers.',
        $enTexte
    ));
});

it('refuse le vert d etat en couleur de texte', function (): void {
    $enTexte = contraste(valeurDuJeton('accent-state'), valeurDuJeton('surface-card'));

    expect($enTexte)->toBeLessThan(4.5, sprintf(
        'Le vert d etat rend %.2f:1 en texte sur une carte : il est fait pour etre un FOND ou une '
        ."marque, pas une couleur de texte. Ce controle existe a l'envers des autres — il constate une "
        .'impossibilite plutot qu une exigence, pour que la regle reste ecrite quelque part le jour ou '
        .'quelqu un voudra ecrire un message de confirmation en vert.',
        $enTexte
    ));
});
