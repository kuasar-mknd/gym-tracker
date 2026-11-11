<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * L'image construit ses actifs sous le Node de l'étape `frontend-builder` du
 * Dockerfile, la CI les construit et les teste sous celui d'`actions/setup-node`,
 * et le poste prend celui de `.nvmrc`, du plancher `engines.node` de
 * package.json et de Sail (`NODE_VERSION` de `compose.yaml`, que l'image de
 * Sail met sinon à son propre défaut). L'image a tourné sur Node 25 quand la
 * CI testait sur 24 : les actifs livrés n'étaient pas ceux qui avaient été
 * testés. Les données de langue changent aussi d'une version à l'autre
 * (`Intl`, `.ai/rules/tests-js.md`). Une montée de Node se fait donc partout à
 * la fois (`.ai/rules/dependencies.md`, « Node monte partout à la fois… »).
 */

/**
 * La majeure d'une version de Node écrite (`26`, `'26'`, `26.13.0`, `>=26`,
 * `26-slim`, `v26.x`) ; null si elle ne se lit pas (`lts/*`, `slim`).
 */
function nodePartoutMajeure(mixed $version): ?int
{
    if (is_int($version)) {
        return $version;
    }

    if (! is_string($version) || preg_match('/^\s*(?:>=\s*)?v?(\d+)(?:[.\-@][^\s]*)?\s*$/', $version, $parties) !== 1) {
        return null;
    }

    return (int) $parties[1];
}

/**
 * Chaque endroit du dépôt qui fixe un Node, avec la majeure qu'il nomme.
 *
 * @return array<string, int|null>
 */
function nodePartoutVersionsDuDepot(): array
{
    $versions = ['.nvmrc' => nodePartoutMajeure(trim((string) file_get_contents(base_path('.nvmrc'))))];

    /** @var array<string, mixed> $paquet */
    $paquet = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $versions['package.json, engines.node'] = nodePartoutMajeure(data_get($paquet, 'engines.node'));

    foreach (file(base_path('Dockerfile')) ?: [] as $rang => $ligne) {
        if (preg_match('/^\s*FROM\s+(?:--\S+\s+)*(?:docker\.io\/)?(?:library\/)?node:(\S+)/i', $ligne, $parties) === 1) {
            $versions['Dockerfile, ligne '.($rang + 1)] = nodePartoutMajeure($parties[1]);
        }
    }

    $workflows = [...(glob(base_path('.github/workflows/*.yml')) ?: []), ...(glob(base_path('.github/workflows/*.yaml')) ?: [])];

    foreach ($workflows as $chemin) {
        /** @var array<string, mixed> $workflow */
        $workflow = Yaml::parseFile($chemin);

        foreach ((array) ($workflow['jobs'] ?? []) as $job => $definition) {
            foreach ((array) data_get($definition, 'steps', []) as $rang => $etape) {
                if (! str_starts_with((string) data_get($etape, 'uses', ''), 'actions/setup-node@')) {
                    continue;
                }

                $fichier = data_get($etape, 'with.node-version-file');
                $version = is_string($fichier)
                    ? trim((string) file_get_contents(base_path($fichier)))
                    : data_get($etape, 'with.node-version');

                $versions[basename($chemin).', job '.$job.', étape '.((int) $rang + 1)] = nodePartoutMajeure($version);
            }
        }
    }

    /** @var array<string, mixed> $compose */
    $compose = Yaml::parseFile(base_path('compose.yaml'));
    $versions['compose.yaml, NODE_VERSION de Sail'] = nodePartoutMajeure($compose['services']['laravel.test']['build']['args']['NODE_VERSION'] ?? null);

    return $versions;
}

it('fixe la même majeure de Node à chaque endroit qui en nomme une', function (): void {
    $versions = nodePartoutVersionsDuDepot();

    $lu = collect($versions)->map(fn (?int $majeure, string $ou): string => $ou.' : '.($majeure ?? 'illisible ou absent'))->implode("\n");

    expect(array_filter($versions, fn (?int $majeure): bool => $majeure === null))
        ->toBe([], "Un endroit ne nomme pas de majeure de Node lisible :\n".$lu)
        ->and(array_values(array_unique($versions)))
        ->toHaveCount(1, "Le dépôt nomme plusieurs majeures de Node :\n".$lu);
});

it('trouve le Node de l’image, de la CI et du poste', function (): void {
    $endroits = array_keys(nodePartoutVersionsDuDepot());

    expect(array_filter($endroits, fn (string $ou): bool => str_starts_with($ou, 'Dockerfile')))->not->toBeEmpty('Le Dockerfile ne construit plus sous une image node : la garde ne le lit plus.')
        ->and(array_filter($endroits, fn (string $ou): bool => str_starts_with($ou, 'ci.yml')))->toHaveCount(2, 'ci.yml installe Node dans frontend-assets et frontend-tests.')
        ->and(array_filter($endroits, fn (string $ou): bool => str_starts_with($ou, 'mutation.yml')))->toHaveCount(1, 'mutation.yml installe Node pour la passe de référence.');
});

it('lit la majeure d’une version de Node écrite', function (mixed $ecrite, ?int $majeure): void {
    expect(nodePartoutMajeure($ecrite))->toBe($majeure);
})->with([
    'un entier, comme setup-node le lit en YAML' => [26, 26],
    'une chaîne' => ['26', 26],
    'une version exacte' => ['26.13.0', 26],
    'une ligne' => ['26.x', 26],
    'préfixée de v' => ['v26.13.0', 26],
    'un plancher' => ['>=26', 26],
    'une image slim' => ['26-slim', 26],
    'une image exacte' => ['26.13.0-slim', 26],
    'une image par digest' => ['26-slim@sha256:5adad64f', 26],
    'un alias de LTS' => ['lts/*', null],
    'une image sans version' => ['slim', null],
    'une plage' => ['^26', null],
    'absente' => [null, null],
]);
