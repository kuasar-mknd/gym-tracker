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
 * Absente ou vide, la liste reste celle d'avant la variable — la boucle locale
 * et les trois plages privées —, pour qu'une installation qui ne la règle pas
 * garde la détection de HTTPS derrière son proxy inverse. Réglée sur la seule
 * adresse sous laquelle le proxy inverse joint l'application, elle empêche un
 * autre pair des plages privées de choisir l'adresse cliente que lisent la
 * liste `ADMIN_ALLOWED_IPS` et les limites de débit par adresse.
 */

$configurees = env('TRUSTED_PROXIES');

$proxies = array_values(array_filter(
    array_map(trim(...), explode(',', is_string($configurees) ? $configurees : '')),
    static fn (string $proxy): bool => $proxy !== '',
));

return [

    'proxies' => $proxies !== [] ? $proxies : [
        '127.0.0.1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ],

];
