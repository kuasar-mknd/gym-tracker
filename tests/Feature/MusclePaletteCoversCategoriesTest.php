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

/**
 * La distance perceptuelle entre deux couleurs, dans l'espace CIELAB.
 *
 * Deux valeurs differentes peuvent etre indistinguables : `#ccff00` et
 * `#c0eb00` sont deux entrees distinctes de la charte, et l'anneau de la
 * bibliotheque les affichait cote a cote sans que personne ne puisse dire ou
 * l'une finissait. L'unicite ne suffit donc pas, il faut l'ecart — et l'ecart
 * ne se juge pas a l'oeil, il se calcule.
 */
function ecartPerceptuel(string $premier, string $second): float
{
    $lab = static function (string $hexa): array {
        $hexa = ltrim($hexa, '#');
        $canaux = [];

        foreach ([0, 2, 4] as $decalage) {
            $canal = hexdec(substr($hexa, $decalage, 2)) / 255;
            $canaux[] = $canal <= 0.04045 ? $canal / 12.92 : (($canal + 0.055) / 1.055) ** 2.4;
        }

        [$rouge, $vert, $bleu] = $canaux;

        $x = ($rouge * 0.4124 + $vert * 0.3576 + $bleu * 0.1805) / 0.95047;
        $y = $rouge * 0.2126 + $vert * 0.7152 + $bleu * 0.0722;
        $z = ($rouge * 0.0193 + $vert * 0.1192 + $bleu * 0.9505) / 1.08883;

        $racine = static fn (float $valeur): float => $valeur > 0.008856
            ? $valeur ** (1 / 3)
            : 7.787 * $valeur + 16 / 116;

        [$x, $y, $z] = [$racine($x), $racine($y), $racine($z)];

        return [116 * $y - 16, 500 * ($x - $y), 200 * ($y - $z)];
    };

    [$l1, $a1, $b1] = $lab($premier);
    [$l2, $a2, $b2] = $lab($second);

    return sqrt(($l1 - $l2) ** 2 + ($a1 - $a2) ** 2 + ($b1 - $b2) ** 2);
}

it('garde les couleurs de categorie distinguables les unes des autres', function (): void {
    /*
     * Le seuil : 25. En dessous, deux parts adjacentes d'un anneau se lisent
     * comme une seule. `cardio` et `legs` etaient a 9, `core` et `shoulders` a
     * 18 — et le commentaire de la charte affirmait pourtant que les huit
     * teintes etaient « separables », ce qui montre que l'oeil ne suffit pas
     * plus ici qu'ailleurs.
     */
    $jetons = array_values(array_unique(correspondanceDesCategories()));
    $trop_proches = [];

    foreach ($jetons as $index => $premier) {
        foreach (array_slice($jetons, $index + 1) as $second) {
            $ecart = ecartPerceptuel(Charte::jeton($premier), Charte::jeton($second));

            if ($ecart < 25.0) {
                $trop_proches[] = sprintf('%s et %s : %.1f', $premier, $second, $ecart);
            }
        }
    }

    expect($trop_proches)->toBe([], sprintf(
        "Ces couleurs de categorie se confondent :\n  %s\n\n"
        .'Un ecart CIELAB sous 25 ne se distingue pas sur un graphique, et sous 10 pas du tout. '
        ."Deux parts d'un meme anneau doivent pouvoir se nommer sans hesiter.",
        implode("\n  ", $trop_proches)
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
