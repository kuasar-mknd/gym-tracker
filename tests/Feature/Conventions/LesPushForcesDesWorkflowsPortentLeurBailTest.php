<?php

declare(strict_types=1);

/*
 * Le rafraichissement hebdomadaire de l'ensemble d'avis hors-ligne poussait sa
 * branche par `git push --force-with-lease origin "$BRANCHE"`, sans valeur
 * attendue (#2026). git compare alors la branche distante a sa reference de
 * suivi locale, que `actions/checkout`, qui ne recupere que main, ne cree
 * jamais : des que la branche existait sur le depot, chaque push etait refuse
 * en « stale info ». Le workflow est reste rouge trois lundis de suite, et plus
 * aucune PR de rafraichissement ne s'ouvrait.
 *
 * Un push force d'un workflow nomme donc la valeur qu'il attend :
 * `--force-with-lease=<branche>:<empreinte>`, l'empreinte lue juste avant
 * l'envoi, vide quand la branche n'existe pas encore.
 */

/**
 * Chaque bail des scripts des workflows, avec son fichier et sa ligne.
 *
 * @return list<array{endroit: string, bail: string}>
 */
function pushForcesDesWorkflowsBaux(): array
{
    $baux = [];

    $chemins = glob(base_path('.github/workflows/*.yml'));

    foreach (is_array($chemins) ? $chemins : [] as $chemin) {
        $lignes = file($chemin);

        if ($lignes === false) {
            throw new RuntimeException("{$chemin} ne se lit pas : la garde ne sait plus ce qu'il pousse.");
        }

        foreach ($lignes as $index => $ligne) {
            if (str_starts_with(ltrim($ligne), '#')) {
                continue;
            }

            preg_match_all('/--force-with-lease\S*/', $ligne, $trouves);

            foreach ($trouves[0] as $bail) {
                $baux[] = ['endroit' => basename($chemin).':'.($index + 1), 'bail' => $bail];
            }
        }
    }

    return $baux;
}

it('donne à chaque push forcé des workflows la valeur qu’il attend', function (): void {
    $baux = pushForcesDesWorkflowsBaux();

    // Le rafraichissement des avis en pousse un : une garde qui ne lirait rien passerait sans rien prouver.
    $dansLeRafraichissement = array_filter(
        $baux,
        fn (array $bail): bool => str_starts_with($bail['endroit'], 'avis-hors-ligne.yml:'),
    );

    expect($dansLeRafraichissement)->not->toBeEmpty();

    $sansAttente = array_values(array_filter(
        $baux,
        fn (array $bail): bool => preg_match('/^--force-with-lease=[^\s:=]+:/', $bail['bail']) !== 1,
    ));

    expect($sansAttente)->toBe([]);
});
