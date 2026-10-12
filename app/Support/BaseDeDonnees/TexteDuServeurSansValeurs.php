<?php

declare(strict_types=1);

namespace App\Support\BaseDeDonnees;

use Exception;
use PDOException;
use ReflectionProperty;

/**
 * Le texte d'une erreur du serveur MySQL ou MariaDB, sans les valeurs qu'il
 * cite (#2032).
 *
 * `mask_bindings_in_exception_messages` tait les valeurs dans la partie
 * « SQL: … » du message d'une `QueryException`, pas dans le texte du serveur
 * qui la précède : une clé en double, un entier ou une date mal formés y
 * citent la valeur refusée (« Duplicate entry 'ada@example.org' for key
 * 'users.users_email_unique' »). Ce texte partait dans les journaux, la table
 * des exceptions et `failed_jobs`.
 *
 * Ce que le texte cite entre apostrophes devient `'?'`, sauf un nom du schéma
 * à une place que le serveur réserve aux noms : en tête (« Column 'x' cannot
 * be null », « Unknown column 'x' ») ou en queue (« for key 'x' », « for
 * column 'x' at row 1 », « in 'field list' »). Une valeur peut contenir des
 * apostrophes, et même imiter une queue : le masque couvre tout ce qui sépare
 * la première apostrophe hors de ces places de la dernière, si bien
 * qu'aucune partie d'une valeur ne reste. L'en-tête de PDO (état SQL, sa
 * description, code du serveur) ne change pas : Laravel y reconnaît une clé
 * en double et une connexion perdue.
 */
final class TexteDuServeurSansValeurs
{
    /**
     * Le texte en quatre morceaux : l'en-tête de PDO, un nom en tête, le
     * milieu, un nom en queue. Seul le milieu est masqué.
     */
    private const string DECOUPE = '/^(?<entete>SQLSTATE\\[[0-9A-Z]{5}\\](?:: [^:\\n]*: \\d+ | \\[\\d+\\] ))?'
        ."(?<tete>(?:Column|Field|Table|Unknown column|Unknown table|Check constraint) '[^']*')?"
        .'(?<milieu>.*?)'
        ."(?<queue> (?:for (?:key|column) '[^']*'(?: at row \\d+)?|in '(?:field list|where clause|order clause|group statement|having clause|on clause)')\\.?)?$/sD";

    /**
     * Ce que le milieu cite, de sa première apostrophe à sa dernière.
     */
    private const string CITATION = "/'.*'/s";

    /**
     * Ce qui remplace un texte que l'expression ne sait pas lire : rien de
     * l'original, plutôt qu'un original entier.
     */
    private const string ILLISIBLE = '[texte du serveur illisible]';

    /**
     * Le texte, sans les valeurs qu'il cite.
     */
    public static function de(string $texte): string
    {
        return preg_replace_callback(
            self::DECOUPE,
            static fn (array $morceaux): string => $morceaux['entete']
                .$morceaux['tete']
                .preg_replace(self::CITATION, "'?'", $morceaux['milieu'])
                .$morceaux['queue'],
            $texte,
            1,
            flags: PREG_UNMATCHED_AS_NULL,
        ) ?? self::ILLISIBLE;
    }

    /**
     * L'exception elle-même, son message et son `errorInfo` masqués. Même
     * objet : sa classe, son état SQL, sa pile et l'endroit où elle est née
     * ne changent pas, et aucune exception précédente ne garde l'original.
     */
    public static function dans(PDOException $exception): PDOException
    {
        new ReflectionProperty(Exception::class, 'message')->setValue($exception, self::de($exception->getMessage()));

        if (is_array($exception->errorInfo)) {
            $exception->errorInfo = array_map(
                static fn (mixed $champ): mixed => is_string($champ) ? self::de($champ) : $champ,
                $exception->errorInfo,
            );
        }

        return $exception;
    }
}
