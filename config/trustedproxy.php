<?php

declare(strict_types=1);

/*
 * Les pairs dont l'application croit les en-têtes `X-Forwarded-*` : l'adresse
 * cliente (`-For`), le schéma (`-Proto`), l'hôte (`-Host`), le port et le
 * préfixe. Un pair hors de cette liste est pris pour le client lui-même, et
 * ce qu'il annonce est ignoré.
 *
 * Le middleware `TrustProxies` de Laravel lit cette clé à chaque requête,
 * faute de liste posée par `trustProxies(at:)` dans `bootstrap/app.php` : la
 * configuration figée par `config:cache` suffit, et rien ne reste d'une
 * requête à l'autre sous Octane.
 *
 * `TRUSTED_PROXIES` : adresses ou sous-réseaux CIDR séparés par des virgules.
 * Absente ou vide, la liste est la boucle locale et la plage des ponts Docker
 * (`172.16.0.0/12`, où tombe la passerelle par laquelle le proxy inverse de
 * l'hôte joint `app`), pour garder la détection de HTTPS. Les réseaux locaux
 * `10.0.0.0/8` et `192.168.0.0/16` n'y sont plus : un appareil du réseau local
 * pris pour un proxy faisait lire l'entrée qu'il forge dans `X-Forwarded-For`.
 * En production, elle se règle sur l'adresse précise sous laquelle le proxy inverse joint
 * l'application (docs/variables-d-environnement.md).
 */

$configurees = env('TRUSTED_PROXIES');

$proxies = array_values(array_filter(
    array_map(trim(...), explode(',', is_string($configurees) ? $configurees : '')),
    static fn (string $proxy): bool => $proxy !== '',
));

return [

    'proxies' => $proxies !== [] ? $proxies : [
        '127.0.0.1',
        '172.16.0.0/12',
    ],

];
