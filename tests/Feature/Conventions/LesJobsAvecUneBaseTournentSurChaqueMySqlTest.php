<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * La montée vers MySQL 9.7 commence par la CI : chaque job de `ci.yml` qui a
 * une base tourne sur la version de la production et sur la LTS suivante,
 * avant que la pile ne bascule dans une release à elle
 * (`.ai/rules/dependencies.md`). La version de la production se lit dans
 * `docker-compose.prod.yml` ; la suivante est une version exacte, que rien ne
 * monte à la main. Sous 9.7, le schéma figé ne se chargeait pas (une colonne
 * générée de Pulse appelait `md5()`), et aucun job ne le voyait.
 *
 * Une matrice renomme le contrôle de son job (`laravel-tests (MySQL 8.4)`) :
 * le contrôle `laravel-tests` qu'attendent la protection de branche et le job
 * `build` reste donc un verdict qui attend toutes les versions, comme
 * `browser-tests` pour les éclats.
 */

/**
 * La version de MySQL de la production : l'étiquette de l'image du service
 * `db` de `docker-compose.prod.yml`.
 */
function mysqlMatriceVersionDeLaProduction(): string
{
    $image = data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'services.db.image');

    expect($image)->toBeString()->toStartWith('mysql:');
    assert(is_string($image));

    return substr($image, strlen('mysql:'));
}

/**
 * Les jobs d'un workflow qui ont un service MySQL, par identifiant.
 *
 * @return array<string, array<string, mixed>>
 */
function mysqlMatriceJobsAvecUneBase(string $workflow): array
{
    $jobs = data_get(Yaml::parseFile(base_path(".github/workflows/{$workflow}")), 'jobs', []);
    $avecUneBase = [];

    foreach (is_array($jobs) ? $jobs : [] as $identifiant => $job) {
        if (is_string($identifiant) && is_array($job) && data_get($job, 'services.mysql') !== null) {
            /** @var array<string, mixed> $job */
            $avecUneBase[$identifiant] = $job;
        }
    }

    return $avecUneBase;
}

it('fait tourner chaque job de la CI qui a une base sur la version de la production et sur la suivante', function (): void {
    $jobs = mysqlMatriceJobsAvecUneBase('ci.yml');
    $production = mysqlMatriceVersionDeLaProduction();

    expect(array_keys($jobs))->toContain('laravel-tests-mysql', 'browser-shard', 'demarrage');

    $listes = [];

    foreach ($jobs as $identifiant => $job) {
        $versions = data_get($job, 'strategy.matrix.mysql');

        expect(data_get($job, 'services.mysql.image'))->toBe('mysql:${{ matrix.mysql }}', "`{$identifiant}` ne prend pas sa base dans la matrice des versions de MySQL.")
            ->and(data_get($job, 'strategy.fail-fast'))->toBeFalse("`{$identifiant}` arrêterait une version au premier échec de l'autre, qui ne dirait plus laquelle tombe.")
            ->and($versions)->toBeArray();
        assert(is_array($versions));

        $listes[$identifiant] = $versions;
    }

    $versions = array_values(array_unique(array_map(serialize(...), $listes)));
    expect($versions)->toHaveCount(1, 'Les jobs qui ont une base ne tournent pas sur les mêmes versions de MySQL : '.json_encode($listes));

    $liste = array_values(array_filter((array) reset($listes), is_string(...)));
    expect($liste)->toBe(reset($listes), 'Une version de la matrice n’est pas une chaîne : l’écrire entre apostrophes.');
    $suivantes = array_slice($liste, 1);

    expect($liste[0] ?? null)->toBe($production, 'La première version de la matrice doit être celle de docker-compose.prod.yml.')
        ->and($suivantes)->not->toBe([], 'La matrice ne teste que la version de la production : la LTS suivante y manque.')
        ->and(array_unique($liste))->toBe($liste);

    foreach ($suivantes as $version) {
        expect($version)->toBeString()->toMatch('/^\d+\.\d+\.\d+$/', 'Une version de la matrice autre que celle de la production suit l’éditeur sans diff : lui donner sa version exacte (X.Y.Z).');
    }
});

it('développe sous Sail sur la version de MySQL de la production', function (): void {
    expect(data_get(Yaml::parseFile(base_path('compose.yaml')), 'services.mysql.image'))
        ->toBe('mysql:'.mysqlMatriceVersionDeLaProduction());
});

it('garde le contrôle laravel-tests : un verdict qui attend chaque version de MySQL', function (): void {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $verdict = data_get($ci, 'jobs.laravel-tests');
    $etapes = implode("\n", array_map(
        static fn (mixed $etape): string => is_string($run = data_get($etape, 'run')) ? $run : '',
        (array) data_get($verdict, 'steps', []),
    ));

    expect($verdict)->toBeArray()
        ->and(data_get($verdict, 'strategy'))->toBeNull()
        ->and(data_get($verdict, 'name'))->toBeNull()
        ->and((array) data_get($verdict, 'needs'))->toContain('laravel-tests-mysql')
        ->and(data_get($verdict, 'if'))->toBe('always()')
        ->and($etapes)->toContain('[ "${{ needs.laravel-tests-mysql.result }}" = "success" ]')
        ->and((array) data_get($ci, 'jobs.build.needs'))->toContain('laravel-tests', 'browser-tests');
});

it('nomme à part les artefacts de chaque version de MySQL', function (): void {
    $artefacts = [];

    foreach (mysqlMatriceJobsAvecUneBase('ci.yml') as $identifiant => $job) {
        foreach ((array) data_get($job, 'steps', []) as $etape) {
            $action = data_get($etape, 'uses');
            $nom = data_get($etape, 'with.name');

            if (is_string($action) && str_starts_with($action, 'actions/upload-artifact@')) {
                $artefacts[] = "{$identifiant} : ".(is_string($nom) ? $nom : '');
            }
        }
    }

    expect($artefacts)->not->toBe([])
        ->and(array_values(array_filter($artefacts, static fn (string $nom): bool => ! str_contains($nom, '${{ matrix.mysql }}'))))
        ->toBe([], 'Deux versions de MySQL écriraient le même artefact, et la seconde échouerait.');
});

/*
 * La passe nocturne juge les tests, que la CI fait déjà tourner sur chaque
 * version ; la doubler doublerait des parts de trente à soixante minutes. Elle
 * suit la production, et la suivra à la bascule.
 */
it('fait tourner la passe nocturne sur la version de MySQL de la production', function (): void {
    $jobs = mysqlMatriceJobsAvecUneBase('mutation.yml');

    expect($jobs)->not->toBe([]);

    foreach ($jobs as $identifiant => $job) {
        expect(data_get($job, 'services.mysql.image'))->toBe('mysql:'.mysqlMatriceVersionDeLaProduction(), "`{$identifiant}` ne tourne pas sur la version de la production.");
    }
});
