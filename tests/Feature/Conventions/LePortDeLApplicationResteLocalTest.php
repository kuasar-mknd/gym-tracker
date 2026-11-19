<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Le proxy inverse est sur le même hôte que le conteneur et vise le port
 * publié par `app`. Publié sur toutes les interfaces, ce port laissait un
 * appareil du réseau local, ou un autre conteneur, forger `X-Forwarded-For`
 * (les plages privées sont des proxys de confiance par défaut) : liste
 * blanche du panneau ouverte, limiteurs par adresse contournés (#2309).
 * La garde vaut pour tous les services : la base et Redis ne se publient pas
 * davantage.
 */

/**
 * Les écarts de chaque service d'une composition : une publication qui
 * n'écoute pas sur la boucle locale seule, ou le réseau de l'hôte, qui expose
 * tout. Chaque écart est précédé du nom du service.
 *
 * @param  array<mixed>  $composition
 * @return list<string>
 */
function portLocalEcarts(array $composition): array
{
    $services = data_get($composition, 'services');

    expect($services)->toBeArray();

    $ecarts = [];

    foreach ((array) $services as $nom => $service) {
        $service = is_array($service) ? $service : [];

        if (($service['network_mode'] ?? null) === 'host') {
            $ecarts[] = "{$nom} : network_mode host";
        }

        foreach ((array) ($service['ports'] ?? []) as $port) {
            $publication = is_scalar($port) ? (string) $port : (string) json_encode($port);
            $ouvert = is_array($port)
                ? ! in_array($port['host_ip'] ?? null, ['127.0.0.1', '::1'], true)
                : ! str_starts_with($publication, '127.0.0.1:') && ! str_starts_with($publication, '[::1]:');

            if ($ouvert) {
                $ecarts[] = "{$nom} : {$publication}";
            }
        }
    }

    return $ecarts;
}

it('ne publie aucun port de la pile ailleurs que sur la boucle locale', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    assert(is_array($composition));

    expect(data_get($composition, 'services.app.ports'))->not->toBe([])
        ->and(portLocalEcarts($composition))->toBe([], 'Un port publié sur toutes les interfaces laisse un pair du réseau forger X-Forwarded-For ou joindre la base (#2309).');
});

it('reconnaît ce qui ouvre un service à tout le réseau', function (array $services, array $attendus): void {
    expect(portLocalEcarts(['services' => $services]))->toBe($attendus);
})->with([
    'app sur toutes les interfaces' => [['app' => ['ports' => ['8888:80']]], ['app : 8888:80']],
    'adresse générique' => [['app' => ['ports' => ['0.0.0.0:8888:80']]], ['app : 0.0.0.0:8888:80']],
    'adresse du réseau local' => [['app' => ['ports' => ['192.168.1.5:8888:80']]], ['app : 192.168.1.5:8888:80']],
    'db publiée' => [['app' => ['ports' => ['127.0.0.1:8888:80']], 'db' => ['ports' => ['3306:3306']]], ['db : 3306:3306']],
    'redis publié' => [['redis' => ['ports' => ['6379:6379']]], ['redis : 6379:6379']],
    'réseau de l’hôte' => [['worker' => ['network_mode' => 'host']], ['worker : network_mode host']],
    'forme longue ouverte' => [['db' => ['ports' => [['target' => 3306, 'published' => 3306]]]], ['db : {"target":3306,"published":3306}']],
    'boucle locale' => [['app' => ['ports' => ['127.0.0.1:8888:80']]], []],
    'boucle locale IPv6' => [['app' => ['ports' => ['[::1]:8888:80']]], []],
    'forme longue locale' => [['app' => ['ports' => [['host_ip' => '127.0.0.1', 'target' => 80, 'published' => 8888]]]], []],
]);
