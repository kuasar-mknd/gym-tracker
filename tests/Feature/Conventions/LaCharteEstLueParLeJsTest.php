<?php

declare(strict_types=1);

/*
 * Le JavaScript porte les NOMS, le CSS porte les VALEURS.
 *
 * Les graphiques dessinent sur canvas : ils ne peuvent pas porter de classes,
 * donc il leur faut des valeurs. Ils en ecrivaient 230 en dur, et la
 * correspondance des categories d'exercice etait recopiee deux fois — en
 * classes dans `Utils/constants.js`, en hexadecimal dans
 * `ExerciseCategoryChart.vue`, sous un commentaire avouant le rapprochement a
 * la main : « Color mapping matching the @theme block ».
 *
 * Ce que ca a produit : deux fichiers ecrivaient `#00FF66` en le commentant
 * `// neon-green`, alors que le jeton vaut `#CCFF00`. Deux verts differents
 * portaient le meme nom, personne ne pouvait s'en apercevoir, et aucun test ne
 * pouvait le voir puisque les deux etaient des litteraux valides.
 *
 * `Utils/couleurs.js` lit desormais les jetons par `getComputedStyle`. Il ne
 * recopie aucune valeur — il ne cite que des noms. Ce controle verifie que
 * chaque nom cite existe reellement dans la charte : c'est la seule jointure
 * qui reste entre les deux mondes, et elle doit tenir.
 */

use Symfony\Component\Finder\Finder;

/**
 * Les noms de jetons cites par `Utils/couleurs.js`.
 *
 * @return list<string>
 */
function jetonsCitesParLeJs(): array
{
    $source = (string) file_get_contents(resource_path('js/Utils/couleurs.js'));

    /*
     * TOUS les noms de jetons cites, pas seulement ceux d'une table.
     *
     * Le motif ne lisait que les valeurs suivant un `:` — donc la
     * correspondance des categories, et rien d'autre. Il capturait 8 noms sur
     * les 14 que ce fichier cite : la liste `SERIE`, qui alimente les series de
     * graphiques, et le repli de `couleurDeCategorie()` passaient a cote. Un
     * garde qui verifie la moitie de ce qu'il annonce est pire qu'aucun garde,
     * parce qu'on lui fait confiance pour le tout.
     */
    preg_match_all("/'([a-z][a-z0-9-]*(?:-[a-z0-9]+)*)'/", $source, $trouves);

    // Les noms de jetons portent tous un tiret ; `strength`, `cardio` n'en sont
    // pas. On ne garde que ce qui ressemble a un jeton, et le controle suivant
    // verifie que chacun existe.
    return array_values(array_unique(array_filter(
        $trouves[1],
        static fn (string $nom): bool => str_contains($nom, '-')
    )));
}

it('ne cite aucun jeton que la charte ne declare pas', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $cites = jetonsCitesParLeJs();

    expect($cites)->not->toBeEmpty('aucun nom de jeton trouve : le controle ne prouverait rien');

    $absents = array_values(array_filter(
        $cites,
        static fn (string $nom): bool => ! str_contains($css, "--color-{$nom}:")
    ));

    expect($absents)->toBe([], sprintf(
        "Ces noms sont cites par le JavaScript et ne sont declares nulle part :\n  %s\n\n"
        .'Un jeton absent rend une chaine vide, donc un graphique dessine sans couleur — ce qui ne '
        .'casse rien et ne se voit que sur la page.',
        implode("\n  ", $absents)
    ));
});

it('ne laisse pas revenir une couleur ecrite en dur dans un graphique', function (): void {
    $fichiers = Finder::create()
        ->files()
        ->in(resource_path('js/Components/Stats'))
        ->name(['*.vue', '*.js']);

    $coupables = [];

    foreach ($fichiers as $fichier) {
        /*
         * Trois OU six chiffres.
         *
         * Le motif n'en lisait que six, et 33 `'#fff'` sont restes dans les
         * graphiques pendant que le compteur affichait zero. Une forme courte
         * est une couleur comme une autre : `#fff` est le blanc de la surface
         * des cartes, recopie a la main, avec exactement le meme defaut que
         * `#ffffff` aurait eu.
         */
        preg_match_all('/#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})\b/', $fichier->getContents(), $trouves);

        if ($trouves[0] !== []) {
            $coupables[$fichier->getRelativePathname()] = count($trouves[0]);
        }
    }

    $total = array_sum($coupables);

    /*
     * Zero depuis le 2026-08-26. Les 326 valeurs sont parties en deux temps : 160
     * etaient l'habillage commun — infobulles, graduations, grilles — qui a
     * trouve sa place dans `chartConfig.js`, et le reste nomme desormais un role
     * via `Utils/couleurs.js`.
     *
     * La traine etait faite de doublons que rien ne pouvait rapprocher : cinq
     * oranges, six roses, sept violets, nes de graphiques ecrits l'un apres
     * l'autre sans registre commun.
     */
    expect($total)->toBe(0, sprintf(
        "Il y a %d couleurs ecrites en dur dans les graphiques, et il n'en faut aucune.\n\n"
        .'Les valeurs se lisent par `Utils/couleurs.js`, qui interroge la charte. Une couleur ecrite '
        ."ici est un litteral que rien ne relie a `app.css` — c'est ainsi que `#00FF66` a pu se faire "
        ."appeler `neon-green` pendant que le jeton valait `#CCFF00`.\n\nFichiers : %s",
        $total,
        json_encode($coupables, JSON_UNESCAPED_SLASHES) === false ? '?' : json_encode($coupables, JSON_UNESCAPED_SLASHES)
    ));
});
