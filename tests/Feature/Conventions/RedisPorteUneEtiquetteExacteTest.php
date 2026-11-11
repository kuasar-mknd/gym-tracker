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
 * la CI suit la production (`LImageDemarreSurUneBaseNeuveTest`).
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
 * L'image du service redis d'une composition.
 */
function redisImageDuService(string $fichier): mixed
{
    $composition = Yaml::parseFile(base_path($fichier));

    return data_get(is_array($composition) ? $composition : [], 'services.redis.image');
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
