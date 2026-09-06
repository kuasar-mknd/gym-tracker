<?php

declare(strict_types=1);

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
