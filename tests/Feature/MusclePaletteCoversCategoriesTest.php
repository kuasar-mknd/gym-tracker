<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;
use App\Support\Charte;

/*
 * Chaque categorie d'exercice a sa couleur, et deux n'ont jamais la meme.
 *
 * Le graphique de repartition indexait autrefois un tableau litteral de sept
 * couleurs. Une categorie ajoutee a l'enum sans couleur correspondante donnait
 * une part sans couleur definie : Chart.js retombait sur son defaut — du noir —
 * et la part cessait de se distinguer. C'est arrive, la palette etant restee a
 * six couleurs quand l'enum est passe a sept (#1382).
 *
 * Ce test grattait donc le composant a la recherche d'hexadecimaux. Il ne le
 * peut plus, et c'est un progres : le composant ne porte plus de tableau, il
 * ecrit `couleurDeCategorie(item.category)` et la couleur se deduit de la
 * categorie elle-meme. Un decalage d'indice n'est plus possible, une categorie
 * absente non plus.
 *
 * Ce qui reste a verifier a donc change de nature. La question n'est plus « le
 * tableau a-t-il la bonne longueur ? » mais « la correspondance couvre-t-elle
 * l'enum, et donne-t-elle bien des couleurs DISTINCTES ? ». Elle vit dans
 * `Utils/couleurs.js`, que rien ne relie a l'enum PHP : le garde doit donc
 * rester ici, du cote qui connait la source de verite.
 *
 * Le meme defaut s'est reproduit sous une autre forme pendant la conversion de
 * la charte : deux categories se sont retrouvees sur le meme jeton, et sept
 * parts n'offraient plus que six couleurs. Le controle d'unicite ci-dessous
 * l'aurait attrape.
 */

/**
 * La correspondance categorie -> jeton, lue dans `Utils/couleurs.js`.
 *
 * @return array<string, string>
 */
function correspondanceDesCategories(): array
{
    $source = (string) file_get_contents(base_path('resources/js/Utils/couleurs.js'));

    if (preg_match('/JETON_PAR_CATEGORIE = \{(.*?)\}/s', $source, $bloc) !== 1) {
        throw new RuntimeException(
            '`JETON_PAR_CATEGORIE` est introuvable dans `Utils/couleurs.js`. Si la correspondance a '.
            'change de forme, ce garde doit suivre — pas etre supprime.'
        );
    }

    preg_match_all("/^\s*([^\s:]+):\s*'([a-z0-9-]+)',/m", $bloc[1], $paires, PREG_SET_ORDER);

    $correspondance = [];

    foreach ($paires as $paire) {
        $correspondance[trim($paire[1], "'\"")] = $paire[2];
    }

    return $correspondance;
}

it('donne une couleur à chaque catégorie d’exercice', function (): void {
    $correspondance = correspondanceDesCategories();

    $sansCouleur = array_values(array_filter(
        array_column(ExerciseCategory::cases(), 'value'),
        static fn (string $categorie): bool => ! array_key_exists($categorie, $correspondance)
    ));

    expect($sansCouleur)->toBe([], sprintf(
        "Ces categories d'exercice n'ont aucune couleur :\n  %s\n\n"
        .'`couleurDeCategorie()` leur rendra le gris de repli, et leur part se confondra avec celle '
        ."des « Autres ». Declarez-les dans `JETON_PAR_CATEGORIE`, et leur jeton dans `app.css`.\n\n"
        .'Connues : %s',
        implode("\n  ", $sansCouleur),
        implode(', ', array_keys($correspondance))
    ));
});

it('ne donne jamais la même couleur à deux catégories', function (): void {
    $correspondance = correspondanceDesCategories();

    // `Core` et `Abdominaux` designent le meme groupe musculaire sous deux noms,
    // l'un venant des donnees historiques : ils partagent leur jeton a dessein.
    unset($correspondance['Abdominaux']);

    $parJeton = [];

    foreach ($correspondance as $categorie => $jeton) {
        $parJeton[$jeton][] = $categorie;
    }

    $partages = array_filter($parJeton, static fn (array $categories): bool => count($categories) > 1);

    expect($partages)->toBe([], sprintf(
        "Ces jetons servent a plusieurs categories :\n  %s\n\n"
        .'Deux parts de la meme couleur dans un anneau ne se distinguent pas, et la legende devient '
        ."illisible. C'est arrive pendant la conversion de la charte : une table de correspondance "
        .'mecanique a replie deux categories sur un meme role, et sept parts sont devenues six.',
        implode("\n  ", array_map(
            static fn (string $jeton, array $categories): string => $jeton.' : '.implode(' et ', $categories),
            array_keys($partages),
            $partages
        ))
    ));
});

it('declare dans la charte chaque jeton que la correspondance cite', function (): void {
    $absents = array_values(array_unique(array_filter(
        correspondanceDesCategories(),
        static function (string $jeton): bool {
            try {
                Charte::jeton($jeton);

                return false;
            } catch (RuntimeException) {
                return true;
            }
        }
    )));

    expect($absents)->toBe([], sprintf(
        "Ces jetons sont cites par la correspondance et ne sont declares nulle part :\n  %s\n\n"
        .'Un jeton absent rend une chaine vide, donc une part dessinee sans couleur — ce qui ne casse '
        .'rien et ne se voit que sur la page. Un anneau aux segments noirs a ete signale ainsi.',
        implode("\n  ", $absents)
    ));
});
