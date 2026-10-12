<?php

declare(strict_types=1);

namespace App\Support\BaseDeDonnees;

use Closure;
use PDOException;

/**
 * Une connexion dont les erreurs ne citent pas les valeurs de la requête,
 * même dans le texte du serveur (#2032).
 *
 * Laravel fait de l'erreur de PDO la précédente de sa `QueryException`, et en
 * recopie le message en tête du sien : le masque passe donc avant, sur
 * l'erreur de PDO elle-même (`TexteDuServeurSansValeurs::dans()`). Laravel
 * lit ensuite le texte masqué comme l'original : il y reconnaît une clé en
 * double, dont il tire encore le nom de l'index, et une connexion perdue.
 * Seul le message change : `QueryException::getBindings()` garde les valeurs
 * pour le code qui rattrape l'exception.
 */
trait TaitLesValeursCiteesParLeServeur
{
    /**
     * Exécute la requête comme Laravel, l'erreur du serveur masquée.
     *
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    #[\Override]
    protected function runQueryCallback($query, $bindings, Closure $callback): mixed
    {
        return parent::runQueryCallback($query, $bindings, static function (mixed $requete, mixed $valeurs) use ($callback): mixed {
            try {
                return $callback($requete, $valeurs);
            } catch (PDOException $erreur) {
                throw TexteDuServeurSansValeurs::dans($erreur);
            }
        });
    }
}
