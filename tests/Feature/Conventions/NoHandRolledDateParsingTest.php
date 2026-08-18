<?php

declare(strict_types=1);

/*
 * `strtotime()` ne dit pas ce qu'il fait, et son faux n'arrive jamais.
 *
 * Six fois dans une seule campagne (#1459, #1474, #1493, #1494, #1495), le meme
 * motif : `strtotime()` sur une colonne NOT NULL, suivi d'un garde
 * `=== false` — ou d'un ternaire — qui ne pouvait pas se declencher. A chaque
 * fois, le repli produisait quelque chose de pire qu'une erreur : une etiquette
 * « ?? » sur une courbe, une chaine brute a la place d'une date ISO, un point de
 * graphique silencieusement saute.
 *
 * A ce stade ce n'est plus une erreur ponctuelle mais une habitude : se proteger
 * d'un cas que le schema interdit deja, et laisser au testeur de mutation le soin
 * de le signaler. Ce garde ferme la famille plutot que les cas.
 *
 * Ce qu'il faut a la place : `CarbonImmutable::parse()`, qui leve sur une entree
 * invalide au lieu de rendre false. Pas de branche a garder, donc pas de branche
 * morte.
 *
 * Le nom d'un garde de convention n'empeche pas de discuter son perimetre : il
 * ne vise que `app/`. Les tests et les migrations peuvent avoir de bonnes raisons
 * de manipuler des horodatages bruts.
 */

/**
 * Les fichiers de l'application, ou une erreur bruyante si l'arborescence a bouge.
 *
 * @return list<string>
 */
function fichiersDeLApplication(): array
{
    $fichiers = [];

    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterateur as $fichier) {
        if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'php') {
            $fichiers[] = $fichier->getPathname();
        }
    }

    if ($fichiers === []) {
        throw new RuntimeException(
            'Aucun fichier trouve dans app/. Le dossier a-t-il ete deplace ? Sans fichiers a lire, '
            .'le garde ci-dessous passerait sans rien verifier.'
        );
    }

    return $fichiers;
}

it('n’analyse pas les dates à la main dans app/', function (): void {
    $fautives = [];

    foreach (fichiersDeLApplication() as $fichier) {
        $lignes = file($fichier);

        if ($lignes === false) {
            throw new RuntimeException("Fichier illisible : {$fichier}");
        }

        foreach ($lignes as $numero => $ligne) {
            /*
             * Les commentaires sont ecartes : cinq services expliquent pourquoi
             * ils n'emploient plus `strtotime()`, et un garde qui punirait son
             * propre mode d'emploi serait retire dans la semaine.
             */
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $ligne) === 1) {
                continue;
            }

            if (preg_match('/\bstrtotime\s*\(/', $ligne) === 1) {
                $fautives[] = sprintf('%s:%d — %s', basename($fichier), $numero + 1, trim($ligne));
            }
        }
    }

    expect($fautives)->toBe([], sprintf(
        '%d appel(s) a strtotime() dans app/. Il rend `false` sur une entree invalide, ce qui oblige '
        .'a un garde — et sur une colonne NOT NULL ce garde ne se declenche jamais. '
        ."`CarbonImmutable::parse()` leve a la place, donc il n'y a pas de branche morte a ecrire :\n- %s",
        count($fautives),
        implode("\n- ", $fautives),
    ));
});

it('regarde bien une arborescence applicative peuplée', function (): void {
    expect(fichiersDeLApplication())->not->toBeEmpty();
});
