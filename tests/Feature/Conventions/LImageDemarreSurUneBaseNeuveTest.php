<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Sur une base neuve, `migrate` charge le dump de schéma par le client
 * `mysql` de l'image, un client MariaDB qui vérifie le certificat du serveur
 * depuis 11.4 alors que MySQL signe le sien lui-même. Sans ce fichier
 * d'options, le premier démarrage échoue et le conteneur boucle (#1767).
 */
it('désactive la vérification du certificat pour le client MySQL de l’image', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain("printf '[client]\\nskip-ssl-verify-server-cert\\n' > /etc/mysql/conf.d/laravel.cnf");
});

/**
 * Le job `demarrage` prouve que l'image démarre contre les services de la
 * pile : il les lançait en Redis 7 quand la production était passée en
 * Redis 8. Ses services suivent désormais les images de la composition de
 * production, et une montée de l'une sans l'autre fait tomber cette garde.
 */
it('lance l’image contre la base et le cache de la production', function (): void {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $production = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    $base = data_get($production, 'services.db.image');
    $cache = data_get($production, 'services.redis.image');

    expect($base)->toBeString()
        ->and($cache)->toBeString()
        ->and(data_get($ci, 'jobs.demarrage.services.mysql.image'))->toBe($base)
        ->and(data_get($ci, 'jobs.demarrage.services.redis.image'))->toBe($cache);
});
