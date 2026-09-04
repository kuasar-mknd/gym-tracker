<?php

declare(strict_types=1);

/*
 * Le baseline PHPStan ne doit que retrecir.
 *
 * `phpstan.neon` le dit deja en commentaire depuis longtemps. Un commentaire
 * n'empeche rien : `phpstan --generate-baseline` reecrit le fichier sans etat
 * d'ame, et une erreur nouvelle s'y range aussi discretement qu'une ancienne.
 *
 * Ce que le baseline coute vraiment s'est vu deux fois aujourd'hui. PHPStan avait
 * signale les deux `assertTrue(true)` du depot — « will always evaluate to true » —
 * et les deux remarques avaient ete rangees ici plutot que corrigees ; l'une
 * couvrait un test de performance qui mesurait le nombre de requetes et le jetait
 * (#1479). Et chaque service ou la campagne de mutation a trouve un vrai defaut
 * avait, sans exception, des entrees dans ce fichier.
 *
 * Le garde est un cliquet, comme les seuils de couverture : le plafond descend
 * quand on draine, il ne remonte que par une decision explicite et visible en
 * revue. Il ne juge pas la qualite des entrees, seulement leur nombre — c'est
 * suffisant pour empecher la derive silencieuse.
 */

/**
 * Le nombre d'erreurs masquees par le baseline, entrees et occurrences.
 *
 * @return array{blocs: int, erreurs: int}
 */
function comptesDuBaseline(): array
{
    $chemin = base_path('phpstan-baseline.neon');

    expect($chemin)->toBeFile();

    $contenu = file_get_contents($chemin);

    if ($contenu === false || $contenu === '') {
        throw new RuntimeException(
            'phpstan-baseline.neon est vide ou illisible. Sans contenu, le garde ci-dessous '
            .'passerait sans rien verifier.'
        );
    }

    $blocs = preg_match_all('/^\t\t\tpath: /m', $contenu);
    $erreurs = 0;

    // Un bloc sans `count:` vaut une occurrence.
    preg_match_all("/\t\t-\n((?:\t\t\t.*\n)+)/", $contenu, $correspondances);

    foreach ($correspondances[1] as $bloc) {
        $erreurs += preg_match('/count: (\d+)/', $bloc, $c) === 1 ? (int) $c[1] : 1;
    }

    if ($blocs === false || $blocs === 0) {
        throw new RuntimeException(
            'Aucune entree lue dans phpstan-baseline.neon. Le format a-t-il change ? '
            .'Un garde qui ne trouve rien a compter passerait sans rien verifier.'
        );
    }

    return ['blocs' => $blocs, 'erreurs' => $erreurs];
}

/*
 * Les plafonds, au niveau MESURE du jour.
 *
 * Pour les baisser : drainer, puis remplacer par le nouveau compte. Pour les
 * monter : il faut une raison, et elle se lira dans la revue — c'est tout
 * l'interet.
 *
 * Repartition au moment de la pose : 630 des 821 erreurs sont dans tests/, et
 * l'essentiel tient au typage de Mockery et des attentes Pest. Les 187 qui
 * comptent sont dans app/, dont 78 « methode appelee sur mixed » et 46 conditions
 * booleennes laches — soit exactement la matiere ou les defauts de cette campagne
 * se cachaient.
 */
const BASELINE_BLOCS_MAX = 231;
const BASELINE_ERREURS_MAX = 418;

it('ne laisse pas grossir le nombre d’entrées du baseline', function (): void {
    $comptes = comptesDuBaseline();

    expect($comptes['blocs'])->toBeLessThanOrEqual(BASELINE_BLOCS_MAX, sprintf(
        'Le baseline PHPStan est passe a %d entrees, au-dessus du plafond de %d. Une erreur nouvelle '
        ."ne se range pas ici : elle se corrige. Si l'ajout est justifie, remonter le plafond "
        .'explicitement dans ce fichier, en disant pourquoi.',
        $comptes['blocs'],
        BASELINE_BLOCS_MAX,
    ));
});

it('ne laisse pas grossir le nombre d’erreurs masquées', function (): void {
    $comptes = comptesDuBaseline();

    // Le compte d'occurrences est le vrai chiffre : une entree peut masquer
    // vingt erreurs sans que le nombre de blocs ne bouge.
    expect($comptes['erreurs'])->toBeLessThanOrEqual(BASELINE_ERREURS_MAX, sprintf(
        'Le baseline PHPStan masque desormais %d erreurs, au-dessus du plafond de %d.',
        $comptes['erreurs'],
        BASELINE_ERREURS_MAX,
    ));
});

/**
 * Et le plafond doit coller a la mesure, sinon il ne sert plus a rien.
 *
 * Un plafond laisse loin au-dessus du compte reel autorise la derive sans jamais
 * rougir. Celui-ci doit etre resserre a chaque drainage, et ce test le rappelle
 * des que l'ecart depasse vingt.
 */
it('garde le plafond collé au compte réel', function (): void {
    $comptes = comptesDuBaseline();

    expect(BASELINE_ERREURS_MAX - $comptes['erreurs'])->toBeLessThanOrEqual(20, sprintf(
        'Le plafond (%d) a pris %d erreurs d’avance sur le compte reel (%d). Le resserrer : un '
        .'plafond qui flotte autorise la derive sans jamais rougir.',
        BASELINE_ERREURS_MAX,
        BASELINE_ERREURS_MAX - $comptes['erreurs'],
        $comptes['erreurs'],
    ));
});

/*
 * Et `app/` n'y revient pas.
 *
 * Le baseline en masquait 187 erreurs a la pose (#1482) : 78 methodes appelees
 * sur `mixed`, 46 conditions booleennes laches, des casts sur l'inconnu. C'est
 * la matiere ou les defauts se cachaient — quatre d'entre eux ont ete trouves
 * en la drainant, du score Wilks dicte par le client au champ de formulaire
 * perdu en silence.
 *
 * Elle est vide. Un plafond chiffre autoriserait d'y remettre une erreur tant
 * qu'il reste de la marge ; cette regle-ci ne laisse aucune marge du tout, et
 * c'est ce qui la rend utile.
 *
 * Les tests, eux, gardent leur baseline : leur typage tient a Mockery et aux
 * attentes de Pest, c'est du frottement d'outillage et non un defaut. La
 * distinction est le fond de #1482.
 */
it('ne laisse plus rentrer app/ dans le baseline', function (): void {
    $contenu = (string) file_get_contents(base_path('phpstan-baseline.neon'));

    preg_match_all('/^\t\t\tpath: (app\/\S+)$/m', $contenu, $trouves);

    expect($trouves[1])->toBe([], sprintf(
        "Ces chemins d'app/ sont revenus dans le baseline :\n  %s\n\n"
        .'app/ en est sorti entierement (187 → 0). Une erreur nouvelle dans le code applicatif '
        .'se corrige : elle designe presque toujours une valeur dont personne ne connait le type, '
        .'et c’est exactement la que les defauts se logeaient.',
        implode("\n  ", $trouves[1])
    ));
});
