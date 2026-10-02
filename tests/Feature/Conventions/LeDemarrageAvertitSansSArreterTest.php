<?php

declare(strict_types=1);

/*
 * Le dossier des sauvegardes est un dossier de l'hôte que le dépôt ne tient pas
 * (#1812). Le démarrage le vérifie et le dit dans `docker logs`, mais un
 * partage cassé ne doit jamais empêcher l'application de démarrer : sous
 * `set -euo pipefail`, un appel nu qui échoue arrêterait le conteneur, et une
 * panne de sauvegarde couperait toute l'application. Un partage endormi ne
 * doit pas non plus retenir le démarrage : la vérification est bornée.
 */

/**
 * Les lignes de `entrypoint.sh`.
 *
 * @return list<string>
 */
function demarrageLignesDeLEntree(): array
{
    return explode("\n", (string) file_get_contents(base_path('entrypoint.sh')));
}

/**
 * Le numéro de la première ligne de `entrypoint.sh` qui contient le texte donné.
 */
function demarrageLigneOu(string $texte): int
{
    foreach (demarrageLignesDeLEntree() as $numero => $ligne) {
        if (str_contains($ligne, $texte)) {
            return $numero;
        }
    }

    throw new RuntimeException("entrypoint.sh ne contient plus « {$texte} » : la garde ne vérifierait rien.");
}

it('vérifie le dossier des sauvegardes au démarrage dans un if, sans jamais arrêter le conteneur', function (): void {
    $lignes = demarrageLignesDeLEntree();
    $appels = array_keys(array_filter($lignes, fn (string $ligne): bool => str_contains($ligne, 'verifier-sauvegardes.sh')));

    expect($appels)->not->toBeEmpty('entrypoint.sh n’appelle plus docker/verifier-sauvegardes.sh.');

    foreach ($appels as $numero) {
        expect($lignes[$numero])->toMatch('/^\s*if\s+!\s/', sprintf(
            "entrypoint.sh, ligne %d : la vérification des sauvegardes doit être la condition d'un `if !`. "
            .'Appelée nue sous `set -e`, elle arrêterait le conteneur sur un partage cassé.',
            $numero + 1,
        ));

        $bloc = [];

        foreach (array_slice($lignes, $numero + 1) as $ligne) {
            if (preg_match('/^\s*fi\b/', $ligne) === 1) {
                break;
            }

            $bloc[] = $ligne;
        }

        expect(preg_grep('/\b(exit|return)\b/', $bloc))->toBe([], sprintf(
            "entrypoint.sh, ligne %d : le bloc qui suit l'échec de la vérification ne doit qu'avertir. "
            ."L'application doit démarrer même si le partage des sauvegardes est cassé.",
            $numero + 1,
        ));
    }
});

it('vérifie le dossier des sauvegardes une fois la configuration figée, avant de lancer le service', function (): void {
    $appel = demarrageLigneOu('verifier-sauvegardes.sh');

    expect($appel)->toBeGreaterThan(demarrageLigneOu('php artisan route:cache'))
        ->toBeLessThan(demarrageLigneOu('exec "$@"'));
});

it('borne la vérification du démarrage, et ne la laisse jamais s’arrêter avant d’avoir averti', function (): void {
    $script = (string) file_get_contents(base_path('docker/verifier-sauvegardes.sh'));

    expect($script)->toContain('timeout ')
        ->and($script)->not->toMatch('/^\s*set\s+-\w*e/m');
});
