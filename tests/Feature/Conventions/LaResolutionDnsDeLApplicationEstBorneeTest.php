<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * `ResolveurDns` appelle `dns_get_record()` dans le fil de la requête qui
 * enregistre un abonnement push, et PHP n'offre aucun délai pour cet appel : il
 * prend celui du résolveur du conteneur (5 s par essai, deux essais par
 * serveur, par défaut). Un serveur DNS muet gardait un processus du serveur web
 * des dizaines de secondes. Les conteneurs qui font tourner PHP règlent donc
 * `timeout` et `attempts` de leur `resolv.conf` (`dns_opt`).
 */
it('borne le résolveur de chaque service qui fait tourner PHP', function (string $service): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    $services = is_array($composition) && is_array($composition['services'] ?? null) ? $composition['services'] : [];
    $options = is_array($services[$service] ?? null) ? ($services[$service]['dns_opt'] ?? []) : [];

    expect($options)->toBe(['timeout:2', 'attempts:2']);
})->with(['app', 'worker', 'scheduler']);
