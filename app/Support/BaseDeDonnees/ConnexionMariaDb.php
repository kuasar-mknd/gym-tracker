<?php

declare(strict_types=1);

namespace App\Support\BaseDeDonnees;

use Illuminate\Database\MariaDbConnection;

/**
 * La connexion `mariadb` de Laravel, dont les erreurs taisent les valeurs que
 * cite le serveur (#2032). `AppServiceProvider` la pose pour le pilote
 * mariadb.
 */
final class ConnexionMariaDb extends MariaDbConnection
{
    use TaitLesValeursCiteesParLeServeur;
}
