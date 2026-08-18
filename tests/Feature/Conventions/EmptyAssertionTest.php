<?php

declare(strict_types=1);

/*
 * Un test qui affirme `true` coche une case sans rien garder.
 *
 * `GoalServicePerformanceTest` montait vingt objectifs sur dix exercices —
 * exactement le jeu qu'il faut pour exposer un N+1 —, comptait les requetes, les
 * imprimait avec `echo`, puis affirmait `assertTrue(true)`. Il mesurait la seule
 * chose qui comptait et jetait la mesure, tout en cochant « performance » sur
 * n'importe quel tableau de couverture.
 *
 * C'est la forme la plus litterale du defaut que cette campagne rencontre partout
 * (#1446) : un vert qui ne prouve rien. La difference avec les autres, c'est
 * qu'elle se detecte par une simple lecture — d'ou ce garde.
 *
 * `echo` est banni pour la meme raison : une valeur imprimee dans la sortie d'une
 * suite de tests n'est lue par personne, et sert presque toujours a remplacer
 * l'assertion qu'on n'a pas ecrite.
 */

/**
 * Les fichiers de test, ou une erreur bruyante si l'arborescence a bouge.
 *
 * @return list<string>
 */
function fichiersDeTest(): array
{
    $fichiers = [];

    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterateur as $fichier) {
        if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'php') {
            $fichiers[] = $fichier->getPathname();
        }
    }

    if ($fichiers === []) {
        throw new RuntimeException(
            'Aucun fichier de test trouve. Le dossier a-t-il ete deplace ? Sans fichiers a lire, '
            .'les gardes ci-dessous passeraient sans rien verifier.'
        );
    }

    return $fichiers;
}

/**
 * Les lignes fautives d'un motif donne, hors commentaires et hors ce fichier.
 *
 * @return list<string>
 */
function lignesFautives(string $motif): array
{
    $fautives = [];

    foreach (fichiersDeTest() as $fichier) {
        // Ce fichier cite les motifs qu'il interdit : un garde qui punirait son
        // propre mode d'emploi serait retire dans la semaine.
        if (basename($fichier) === 'EmptyAssertionTest.php') {
            continue;
        }

        $lignes = file($fichier);

        if ($lignes === false) {
            throw new RuntimeException("Fichier de test illisible : {$fichier}");
        }

        foreach ($lignes as $numero => $ligne) {
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $ligne) === 1) {
                continue;
            }

            if (preg_match($motif, $ligne) === 1) {
                $fautives[] = sprintf('%s:%d — %s', basename($fichier), $numero + 1, trim($ligne));
            }
        }
    }

    return $fautives;
}

it('n’affirme pas que vrai est vrai', function (): void {
    $fautives = lignesFautives('/assert(True|Equals)\s*\(\s*true\s*[,)]/i');

    expect($fautives)->toBe([], sprintf(
        '%d assertion(s) qui ne verifient rien. Un test qui affirme `true` coche une case sans '
        ."rien garder — asserez ce que le test vient de mesurer :\n- %s",
        count($fautives),
        implode("\n- ", $fautives),
    ));
});

it('n’imprime rien au lieu d’asserter', function (): void {
    $fautives = lignesFautives('/^\s*(echo|print|var_dump|dump|dd)\s*[\s(\'"$]/');

    expect($fautives)->toBe([], sprintf(
        "%d impression(s) dans la suite. Personne ne lit la sortie d'une suite verte : ce qui merite "
        ."d'etre imprime merite d'etre asserte :\n- %s",
        count($fautives),
        implode("\n- ", $fautives),
    ));
});

it('regarde bien une arborescence de tests peuplée', function (): void {
    expect(fichiersDeTest())->not->toBeEmpty();
});
