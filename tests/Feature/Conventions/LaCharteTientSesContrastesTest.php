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
 * La luminance relative d'une couleur, selon WCAG 2.1.
 */
function luminance(string $hexa): float
{
    $hexa = ltrim($hexa, '#');

    $canaux = [];

    foreach ([0, 2, 4] as $decalage) {
        $canal = hexdec(substr($hexa, $decalage, 2)) / 255;
        $canaux[] = $canal <= 0.03928 ? $canal / 12.92 : (($canal + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $canaux[0] + 0.7152 * $canaux[1] + 0.0722 * $canaux[2];
}

function contraste(string $premier, string $second): float
{
    $a = luminance($premier);
    $b = luminance($second);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/**
 * La valeur d'un jeton, lue dans le bloc `@theme`.
 *
 * Les alias — ceux qui pointent vers un autre jeton — sont suivis, sinon le
 * controle passerait a cote de toute paire ecrite avec un ancien nom.
 */
function valeurDuJeton(string $nom): string
{
    /** @var string|null $css */
    static $css = null;
    $css ??= (string) file_get_contents(resource_path('css/app.css'));

    for ($saut = 0; $saut < 5; $saut++) {
        if (preg_match('/--color-'.preg_quote($nom, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $direct) === 1) {
            return strtolower($direct[1]);
        }

        if (preg_match('/--color-'.preg_quote($nom, '/').':\s*var\(--color-([a-z0-9-]+)\)\s*;/', $css, $alias) !== 1) {
            break;
        }

        $nom = $alias[1];
    }

    throw new RuntimeException("Le jeton `--color-{$nom}` n'est pas declare, ou sa valeur n'est pas une couleur pleine.");
}

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
        ['text-on-accent', 'accent-primary', "encre sur l'accent principal"],
        ['text-on-accent', 'accent-state', "encre sur le vert d'etat"],
        ['text-on-accent', 'accent-info', "encre sur l'information"],
        ['text-on-accent', 'accent-warning', "encre sur l'alerte"],
        ['text-on-accent', 'accent-state', "encre sur l'action principale, qui porte ce vert"],
        ['trend-up', 'surface-card', 'une hausse, ecrite sur une carte'],
        ['trend-down', 'surface-card', 'une baisse, ecrite sur une carte'],
        ['accent-danger-deep', 'surface-card', 'un libelle de danger sur une carte'],
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

it('garde du blanc lisible sur l accent profond, qui existe pour ca', function (): void {
    $surProfond = contraste('#ffffff', valeurDuJeton('accent-primary-deep'));
    $surVif = contraste('#ffffff', valeurDuJeton('accent-primary'));

    expect($surProfond)->toBeGreaterThanOrEqual(4.5, sprintf(
        '`--color-accent-primary-deep` ne sert qu a porter du texte blanc, et il rend %.2f:1. '
        ."S'il ne tient plus ce role, il n'a plus de raison d'exister : autant revenir a un seul orange.",
        $surProfond
    ));

    // Et la raison d'etre du doublon : le vif ne peut PAS porter de blanc.
    expect($surVif)->toBeLessThan(4.5, sprintf(
        "`--color-accent-primary` rend %.2f:1 avec du blanc — s'il passait le seuil, l'accent profond "
        .'serait inutile et la charte pourrait se simplifier.',
        $surVif
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
