<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Support\GardeDesParcours;

/**
 * Sous Sail, `artisan dusk` visait la base de développement, que les parcours
 * vident au premier test, et envoyait le navigateur du conteneur `selenium`
 * sur `localhost` (#1909). `GardeDesParcours` refuse désormais ces deux
 * dispositions au début de chaque parcours. Ces gardes tiennent ce qui
 * l'entoure : la CI doit la passer, sinon les trois éclats `browser-shard`
 * tombent et seule la CI le verrait ; la recette Sail du README aussi ; la
 * base qu'elles nomment doit exister ; et phpunit.dusk.xml ne doit plus
 * prétendre régler ce que le .env décide.
 */

/**
 * Le .env qu'écrit l'étape « Run Dusk Tests » du job browser-shard.
 *
 * @return array<string, string>
 */
function parcoursDuskEnvDeLaCi(): array
{
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $etapes = is_array($ci) && is_array($ci['jobs'] ?? null) && is_array($ci['jobs']['browser-shard'] ?? null)
        ? ($ci['jobs']['browser-shard']['steps'] ?? [])
        : [];

    foreach (is_array($etapes) ? $etapes : [] as $etape) {
        $script = is_array($etape) ? ($etape['run'] ?? null) : null;

        if (is_string($script) && preg_match('/^cat <<EOF > \.env\n(.*?)\nEOF$/ms', $script, $heredoc) === 1) {
            preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', $heredoc[1], $lignes, PREG_SET_ORDER);

            return array_column($lignes, 2, 1);
        }
    }

    return [];
}

/**
 * Les valeurs que la recette Sail du README pose dans .env.dusk.local.
 *
 * @return array<string, string>
 */
function parcoursDuskRecetteDuReadme(): array
{
    $readme = (string) file_get_contents(base_path('README.md'));

    preg_match_all('/s#\^([A-Z][A-Z0-9_]*)=\.\*#\1=([^#]+)#/', $readme, $remplacements, PREG_SET_ORDER);

    return array_column($remplacements, 2, 1);
}

it('laisse passer la disposition du job browser-shard', function (): void {
    $env = parcoursDuskEnvDeLaCi();

    expect($env)->toHaveKeys(['DB_DATABASE', 'APP_URL', 'DUSK_DRIVER_URL'])
        ->and(GardeDesParcours::motifsDeRefus($env['DB_DATABASE'], $env['APP_URL'], $env['DUSK_DRIVER_URL']))
        ->toBe([]);

    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain('MYSQL_DATABASE: '.$env['DB_DATABASE']);
});

it('donne une recette Sail que la garde laisse passer', function (): void {
    $recette = parcoursDuskRecetteDuReadme();

    expect($recette)->toHaveKeys(['DB_DATABASE', 'APP_URL', 'DUSK_DRIVER_URL', 'DB_HOST'])
        ->and($recette['DB_HOST'])->toBe('mysql')
        ->and(GardeDesParcours::motifsDeRefus($recette['DB_DATABASE'], $recette['APP_URL'], $recette['DUSK_DRIVER_URL']))
        ->toBe([])
        ->and($recette['DB_DATABASE'])->toBe(parcoursDuskEnvDeLaCi()['DB_DATABASE'] ?? null)
        ->and((string) file_get_contents(base_path('README.md')))->toContain('.env > .env.dusk.local');
});

it('fait créer la base des parcours par le MySQL de Sail, et l’ouvre à son utilisateur', function (): void {
    $base = parcoursDuskRecetteDuReadme()['DB_DATABASE'] ?? 'absente du README';
    $script = (string) file_get_contents(base_path('docker/mysql/create-parallel-testing-databases.sh'));
    $composition = (string) file_get_contents(base_path('compose.yaml'));

    expect($script)->toContain("CREATE DATABASE IF NOT EXISTS \\`{$base}\\`;")
        ->and($script)->toContain("GRANT ALL PRIVILEGES ON \\`{$base}\\`.* TO '\$MYSQL_USER'@'%';")
        ->and($composition)->toContain('./docker/mysql/create-parallel-testing-databases.sh:/docker-entrypoint-initdb.d/20-create-parallel-testing-databases.sh')
        ->and((string) file_get_contents(base_path('README.md')))
        ->toContain('sail exec mysql bash /docker-entrypoint-initdb.d/20-create-parallel-testing-databases.sh');
});

it('ne règle dans phpunit.dusk.xml ni la base, ni son hôte, ni l’URL de l’application', function (): void {
    $configuration = simplexml_load_file(base_path('phpunit.dusk.xml'));
    $variables = $configuration === false ? false : $configuration->xpath('/phpunit/php/*');

    expect($variables)->toBeArray();

    $reglees = [];

    foreach (is_array($variables) ? $variables : [] as $variable) {
        $reglees[] = (string) $variable['name'];
    }

    expect($reglees)->not->toBe([])
        ->and(array_values(array_intersect($reglees, ['DB_DATABASE', 'DB_HOST', 'DB_URL', 'APP_URL'])))->toBe([]);
});
