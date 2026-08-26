<?php

declare(strict_types=1);

/*
 * Une variable de couleur ne doit pas recopier la valeur d'une autre.
 *
 * Le depot porte DEUX familles, et elles ne sont pas la meme chose :
 *
 *  - `--color-text-muted`, declaree dans le bloc `@theme`. C'est celle que
 *    Tailwind lit pour produire `.text-text-muted { color: var(...) }`, donc
 *    celle que le HTML utilise reellement ;
 *  - `--text-muted`, une variable historique lue par les regles CSS ecrites a
 *    la main.
 *
 * Tant que chacune portait sa propre valeur litterale, elles pouvaient diverger
 * — et elles l'ont fait. Sous le mode sombre, seule la seconde etait renversee :
 * les 363 usages de `text-text-muted` gardaient donc leur gris clair sur fond
 * sombre, mesure au navigateur a 1,96:1 la ou la norme AA demande 4,5:1. Pas
 * « un peu juste » : illisible, et ca expliquait a soi seul l'essentiel du grief
 * de #1317.
 *
 * Le mode sombre a depuis ete retire (#1580) et cette divergence-la ne peut plus
 * se produire par ce chemin. La cause, elle, n'a pas disparu : deux variables
 * qui portent chacune leur litteral divergeront au premier changement de
 * palette, et la refonte qui vient va precisement en changer une. La variable
 * historique doit donc REFERENCER le jeton, ce qui rend l'ecart impossible
 * plutot qu'improbable.
 *
 * Le piege est qu'un tel oubli ne casse rien : la page s'affiche, les tests
 * passent, et seul l'oeil devant l'ecran sait. D'ou ce controle.
 */

it('fait pointer les variables historiques vers les jetons, plutot que de les recopier', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (['text-main', 'text-muted'] as $nom) {
        // `toContain()` prend des aiguilles, pas un message : lui passer les
        // deux fait chercher le message dans le fichier.
        expect(str_contains($css, "--{$nom}: var(--color-{$nom});"))->toBeTrue(
            "`--{$nom}` doit referencer `--color-{$nom}` plutot que recopier sa valeur : "
            .'c\'est en portant chacune la sienne que les deux familles ont diverge.'
        );
    }
});
