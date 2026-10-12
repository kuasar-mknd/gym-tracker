<?php

declare(strict_types=1);

namespace App\Support\BaseDeDonnees;

use Illuminate\Database\MySqlConnection;

/**
 * La connexion `mysql` de Laravel, dont les erreurs taisent les valeurs que
 * cite le serveur (#2032). `AppServiceProvider` la pose pour le pilote mysql.
 */
final class ConnexionMySql extends MySqlConnection
{
    use TaitLesValeursCiteesParLeServeur;
}
