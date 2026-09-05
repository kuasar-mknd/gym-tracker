<?php

declare(strict_types=1);

/**
 * Une pause fixe est un pari sur la vitesse de la machine : trop courte, le
 * test tombe sur un coureur lent ; trop longue, chaque passe la paie. Il y en
 * avait cinquante, 39,8 s par passe (#1674). Chaque attente nomme desormais sa
 * condition : `waitFor*`, `waitUntil`, `clickWhenSettled`, `waitForServerIds`,
 * `waitForStableLayout`, ou `waitForDatabase` pour une ecriture debouncee.
 */
it('n’attend jamais une durée dans un test de navigateur', function (): void {
    $fichiers = glob(base_path('tests/Browser/*Test.php'));
    $fautifs = [];

    foreach ($fichiers === false ? [] : $fichiers as $chemin) {
        $contenu = file_get_contents($chemin);

        if ($contenu !== false && str_contains($contenu, '->pause(')) {
            $fautifs[] = 'tests/Browser/'.basename($chemin);
        }
    }

    expect($fautifs)->toBe([]);
});
