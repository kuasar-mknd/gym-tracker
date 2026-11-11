<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Le service redis n'a pas de `pull_policy` : Compose ne tire son image que
 * si elle manque. Une étiquette flottante (`8-alpine`, `alpine`) reste donc
 * sur la version tirée la première fois, et rien ne dit laquelle sert (#2152).
 * Une étiquette exacte dit ce qui tourne, et sa montée, dans la composition
 * de la release, fait tirer et recréer le service. La production et Sail la
 * portent, Sail à la même version que la production ; le job `demarrage` de
 * la CI suit la production (`LImageDemarreSurUneBaseNeuveTest`). Tout autre
 * service de Redis (le `redis-cache` de l'étape 2 de #2151) porte la même
 * étiquette que `redis` : posé flottant, il retomberait dans le défaut corrigé.
 *
 * MySQL reste hors de cette garde jusqu'à la PR de la bascule vers 9.7 :
 * figer `mysql:8.4` à son correctif recréerait db à la mise à jour suivante,
 * hors de toute marche (`.ai/rules/dependencies.md`).
 */

/**
 * Une étiquette exacte : trois nombres, et au plus une variante en
 * minuscules (`8.10.3`, `8.10.3-alpine`, `8.10.3-alpine3.23`). Ni une ligne
 * (`8.10`), ni une majeure (`8-alpine`), ni une variante seule (`alpine`),
 * ni `latest`, ni une image sans étiquette.
 */
function redisEtiquetteEstExacte(string $image): bool
{
    if (! str_contains($image, ':')) {
        return false;
    }

    $etiquette = substr($image, (int) strrpos($image, ':') + 1);

    return preg_match('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', $etiquette) === 1;
}

/**
 * Une composition du dépôt, lue.
 *
 * @return array<array-key, mixed>
 */
function redisCompositionLue(string $fichier): array
{
    $composition = Yaml::parseFile(base_path($fichier));

    return is_array($composition) ? $composition : [];
}

/**
 * L'image du service redis d'une composition.
 */
function redisImageDuService(string $fichier): mixed
{
    return data_get(redisCompositionLue($fichier), 'services.redis.image');
}

/**
 * Les services de Redis d'une composition et leur image : ceux dont le nom
 * commence par `redis`, et ceux qui lancent l'image `redis`, qualifiée ou non.
 *
 * @param  array<array-key, mixed>  $composition
 * @return array<string, mixed>
 */
function redisServicesDeLaComposition(array $composition): array
{
    $services = is_array($composition['services'] ?? null) ? $composition['services'] : [];
    $images = [];

    foreach ($services as $nom => $service) {
        $image = is_array($service) ? ($service['image'] ?? null) : null;
        $depot = is_string($image) ? (string) preg_replace('/:[^:\/]*$/', '', $image) : '';

        if (str_starts_with((string) $nom, 'redis') || $depot === 'redis' || str_ends_with($depot, '/redis')) {
            $images[(string) $nom] = $image;
        }
    }

    return $images;
}

/**
 * Ce que la garde refuse dans une composition : un service de Redis sans
 * étiquette exacte, ou à une autre étiquette que le service `redis`.
 *
 * @param  array<array-key, mixed>  $composition
 * @return list<string>
 */
function redisEcartsDesServices(array $composition): array
{
    $reference = data_get($composition, 'services.redis.image');
    $ecarts = [];

    foreach (redisServicesDeLaComposition($composition) as $nom => $image) {
        if (! is_string($image) || ! redisEtiquetteEstExacte($image)) {
            $ecarts[] = sprintf('%s lance %s, une étiquette qui n\'est pas exacte', $nom, is_string($image) ? $image : 'une image sans nom');
        } elseif ($image !== $reference) {
            $ecarts[] = sprintf('%s lance %s, redis lance %s', $nom, $image, is_string($reference) ? $reference : 'rien');
        }
    }

    return $ecarts;
}

it('reconnaît une étiquette exacte', function (string $image, bool $exacte): void {
    expect(redisEtiquetteEstExacte($image))->toBe($exacte);
})->with([
    'version et variante' => ['redis:8.10.3-alpine', true],
    'version seule' => ['redis:8.10.3', true],
    'variante versionnée' => ['redis:8.10.3-alpine3.23', true],
    'image qualifiée' => ['docker.io/library/redis:8.10.3-alpine', true],
    'version au calendrier' => ['mysql:26.10.0', true],
    'majeure et variante' => ['redis:8-alpine', false],
    'variante seule' => ['redis:alpine', false],
    'ligne' => ['redis:8.10', false],
    'ligne et variante' => ['redis:8.10-alpine', false],
    'latest' => ['redis:latest', false],
    'sans étiquette' => ['redis', false],
    'préfixe v' => ['redis:v8.10.3', false],
    'variante en capitales' => ['redis:8.10.3-Alpine', false],
    'quatre nombres' => ['redis:8.10.3.1', false],
    'candidate' => ['redis:8.12-rc2-alpine', false],
]);

it('lance redis à une étiquette exacte en production et sous Sail, Sail à la version de la production', function (): void {
    $production = redisImageDuService('docker-compose.prod.yml');
    $sail = redisImageDuService('compose.yaml');

    expect($production)->toBeString()->toStartWith('redis:')
        ->and($sail)->toBeString()->toStartWith('redis:');
    assert(is_string($production) && is_string($sail));

    expect(redisEtiquetteEstExacte($production))->toBeTrue("docker-compose.prod.yml lance {$production}, une étiquette qui n'est pas exacte")
        ->and(redisEtiquetteEstExacte($sail))->toBeTrue("compose.yaml lance {$sail}, une étiquette qui n'est pas exacte")
        ->and($sail)->toBe($production);
});

it('lance chaque service de Redis à l’étiquette exacte du service redis, dans les deux compositions', function (string $fichier): void {
    $composition = redisCompositionLue($fichier);

    expect(redisServicesDeLaComposition($composition))->toHaveKey('redis')
        ->and(redisEcartsDesServices($composition))->toBe([]);
})->with(['docker-compose.prod.yml', 'compose.yaml']);

it('refuse un autre service de Redis à une étiquette flottante ou à une autre version', function (string $nom, string $image, string $ecart): void {
    $composition = redisCompositionLue('docker-compose.prod.yml');
    $production = redisImageDuService('docker-compose.prod.yml');
    $services = is_array($composition['services'] ?? null) ? $composition['services'] : [];
    $composition['services'] = [$nom => ['image' => $image, 'restart' => 'always', 'networks' => ['backend']], ...$services];

    expect(redisEcartsDesServices($composition))->toBe([str_replace('{production}', is_string($production) ? $production : '', $ecart)]);
})->with([
    'redis-cache flottant' => ['redis-cache', 'redis:8-alpine', 'redis-cache lance redis:8-alpine, une étiquette qui n\'est pas exacte'],
    'redis-cache à une autre version' => ['redis-cache', 'redis:8.10.2-alpine', 'redis-cache lance redis:8.10.2-alpine, redis lance {production}'],
    'image qualifiée sous un autre nom' => ['cache', 'docker.io/library/redis:alpine', 'cache lance docker.io/library/redis:alpine, une étiquette qui n\'est pas exacte'],
]);
