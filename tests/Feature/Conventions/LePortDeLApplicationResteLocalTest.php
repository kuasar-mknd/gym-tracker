<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Le proxy inverse est sur le même hôte que le conteneur et vise le port
 * publié par `app`. Publié sur toutes les interfaces, ce port laissait un
 * appareil du réseau local, ou un autre conteneur, forger `X-Forwarded-For`
 * (les plages privées sont des proxys de confiance par défaut) : liste
 * blanche du panneau ouverte, limiteurs par adresse contournés (#2309).
 */

/**
 * Les publications de ports de `app` dans une composition, en chaînes.
 *
 * @param  array<mixed>  $composition
 * @return list<string>
 */
function portLocalPublications(array $composition): array
{
    $ports = data_get($composition, 'services.app.ports');

    expect($ports)->toBeArray();

    return array_values(array_map(
        static fn (mixed $port): string => is_scalar($port) ? (string) $port : (string) json_encode($port),
        (array) $ports,
    ));
}

/**
 * Les publications qui n'écoutent pas sur la boucle locale seule.
 *
 * @param  list<string>  $publications
 * @return list<string>
 */
function portLocalEcarts(array $publications): array
{
    return array_values(array_filter(
        $publications,
        static fn (string $publication): bool => ! str_starts_with($publication, '127.0.0.1:') && ! str_starts_with($publication, '[::1]:'),
    ));
}

it('ne publie le port de l’application que sur la boucle locale', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    assert(is_array($composition));

    $publications = portLocalPublications($composition);

    expect($publications)->not->toBe([])
        ->and(portLocalEcarts($publications))->toBe([], 'Un port publié sur toutes les interfaces laisse un pair du réseau forger X-Forwarded-For (#2309).');
});

it('reconnaît une publication ouverte à tout le réseau', function (string $publication, bool $ouverte): void {
    expect(portLocalEcarts([$publication]) !== [])->toBe($ouverte);
})->with([
    'toutes les interfaces' => ['8888:80', true],
    'adresse générique' => ['0.0.0.0:8888:80', true],
    'adresse du réseau local' => ['192.168.1.5:8888:80', true],
    'boucle locale' => ['127.0.0.1:8888:80', false],
    'boucle locale IPv6' => ['[::1]:8888:80', false],
]);
