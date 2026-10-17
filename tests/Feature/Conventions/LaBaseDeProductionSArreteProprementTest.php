<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Sans `stop_grace_period`, la composition laisse dix secondes à mysqld avant
 * de le couper net : un arrêt, un redémarrage ou une recréation du service
 * `db` n'est propre que si le serveur a fini d'écrire son tampon d'ici là. La
 * bascule vers MySQL 9.7 commence par cet arrêt, et la copie du volume qui le
 * suit doit être celle d'un arrêt propre (`.ai/rules/dependencies.md`).
 */

/**
 * Une durée de la composition (`5m`, `1m30s`, `300s`) en secondes ; null si
 * elle ne se lit pas.
 */
function arretPropreDureeEnSecondes(mixed $duree): ?int
{
    if (! is_string($duree) || $duree === '' || preg_match('/^(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+)s)?$/', $duree, $parties) !== 1) {
        return null;
    }

    return (int) ($parties[1] ?? 0) * 3600 + (int) ($parties[2] ?? 0) * 60 + (int) ($parties[3] ?? 0);
}

it('laisse au service db de la production cinq minutes pour s’arrêter', function (): void {
    $delai = data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'services.db.stop_grace_period');

    expect(arretPropreDureeEnSecondes($delai))->toBeGreaterThanOrEqual(300, 'Le service db s’arrête en dix secondes, puis mysqld est coupé net.');
});

it('lit les durées de la composition', function (mixed $duree, ?int $secondes): void {
    expect(arretPropreDureeEnSecondes($duree))->toBe($secondes);
})->with([
    'en minutes' => ['5m', 300],
    'en minutes et secondes' => ['1m30s', 90],
    'en secondes' => ['300s', 300],
    'en heures' => ['1h', 3600],
    'en millisecondes' => ['300ms', null],
    'vide' => ['', null],
    'absente' => [null, null],
    'en nombre' => [300, null],
]);
