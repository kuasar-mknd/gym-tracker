<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Un conteneur garde l'image avec laquelle il a été créé (#1813) : pour que
 * la page « Santé » compare app, worker et scheduler, l'image doit savoir ce
 * qu'elle est. La CI lui passe son tag et son commit au build ; l'image les
 * recopie en variables d'environnement, que `config/app.php` lit.
 */

/**
 * Les numéros des lignes du `Dockerfile` qui répondent au motif donné.
 *
 * @return list<int>
 */
function imageLignesDuDockerfile(string $motif): array
{
    $lignes = explode("\n", (string) file_get_contents(base_path('Dockerfile')));
    $trouvees = preg_grep($motif, $lignes);

    return $trouvees === false ? [] : array_keys($trouvees);
}

it('inscrit la version et la révision dans l’image après le dernier RUN, pour garder le cache de construction', function (): void {
    $arguments = imageLignesDuDockerfile('/^ARG APP_(VERSION=dev|REVISION=inconnue)$/');
    $environnement = imageLignesDuDockerfile('/^ENV APP_(VERSION=\$\{APP_VERSION\}|REVISION=\$\{APP_REVISION\})$/');
    $executions = imageLignesDuDockerfile('/^RUN\s/');
    $utilisateur = imageLignesDuDockerfile('/^USER www-data$/');

    expect($arguments)->toHaveCount(2)
        ->and($environnement)->toHaveCount(2)
        ->and($executions)->not->toBeEmpty()
        ->and($utilisateur)->toHaveCount(1);
    assert($arguments !== [] && $environnement !== [] && $executions !== []);

    // Un RUN placé après un ARG reçoit sa valeur : un commit neuf le relancerait,
    // et tout ce qui suit, à chaque construction.
    expect(min($arguments))->toBeGreaterThan(max($executions), 'Les ARG de version doivent suivre le dernier RUN du Dockerfile.')
        // Un ENV placé avant son ARG recopierait une valeur vide.
        ->and(min($environnement))->toBeGreaterThan(max($arguments), 'Les ENV de version doivent suivre leurs ARG : avant eux, ${APP_VERSION} est vide.')
        ->and(max($environnement))->toBeLessThan($utilisateur[0]);
});

it('fait passer à l’image son tag et son commit par la CI', function (): void {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $etapes = collect((array) data_get($ci, 'jobs.build.steps', []));

    $meta = $etapes->firstWhere('id', 'meta');
    $construction = $etapes->first(fn (mixed $etape): bool => is_array($etape) && is_string($etape['uses'] ?? null) && str_starts_with($etape['uses'], 'docker/build-push-action@'));

    expect(data_get($meta, 'uses'))->toBeString()->toStartWith('docker/metadata-action@')
        ->and($construction)->toBeArray();

    $argumentsDeConstruction = data_get($construction, 'with.build-args');

    expect($argumentsDeConstruction)->toBeString('Le job build ne passe aucun build-args à l’image.');
    assert(is_string($argumentsDeConstruction));

    $arguments = preg_split('/\R/', trim($argumentsDeConstruction));

    expect($arguments)->toContain('APP_VERSION=${{ steps.meta.outputs.version }}')
        ->toContain('APP_REVISION=${{ github.sha }}');
});
