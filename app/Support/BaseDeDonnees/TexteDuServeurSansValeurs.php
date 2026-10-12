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
 * à la place que lui réserve une forme connue du serveur : en tête (« Column
 * 'x' cannot be null ») ou en queue (« Duplicate entry '…' for key 'x' »,
 * « Incorrect … value: '…' for column 'x' at row 1 », « Unknown column 'x' in
 * 'field list' »). Une queue n'est gardée qu'au bout de la forme qui la
 * porte : le serveur écrit certaines valeurs en dernier (« Truncated
 * incorrect INTEGER value: '…' »), et une telle valeur peut finir comme une
 * queue. Entre le début et la queue, le masque couvre tout ce qui sépare la
 * première apostrophe de la dernière : une valeur qui contient des
 * apostrophes, ou qui imite une queue, ne laisse rien passer. Une forme
 * inconnue perd tout ce qu'elle cite. L'en-tête de PDO (état SQL, sa
 * description, code du serveur) ne change pas : Laravel y reconnaît une clé
 * en double et une connexion perdue.
 */
final class TexteDuServeurSansValeurs
{
    /**
     * Le texte en quatre morceaux : l'en-tête de PDO, le début de la forme
     * (un nom compris), le milieu, la queue (un nom). Seul le milieu est
     * masqué. Une ligne par forme connue, la dernière pour toutes les autres.
     *
     * Chaque queue va avec le début de sa forme : « for key 'x' » ne finit
     * qu'une clé en double, « for column 'x' at row N » qu'une valeur mal
     * formée, trop longue, coupée ou hors bornes. Ailleurs, la même fin
     * peut venir d'une valeur, et reste dans le milieu.
     */
    private const string DECOUPE = '/^(?<entete>SQLSTATE\\[[0-9A-Z]{5}\\](?:: [^:\\n]*: \\d+ | \\[\\d+\\] ))?(?|'
        ."(?<tete>Duplicate entry)(?<milieu>.*)(?<queue> for key '[^']*')"
        ."|(?<tete>Incorrect [^:'\\n]* value:|Data too long|Data truncated|Out of range value)(?<milieu>.*)(?<queue> for column '[^']*' at row \\d+)"
        ."|(?<tete>Invalid JSON text:)(?<milieu>.*)(?<queue> for column '[^']*'\\.)"
        ."|(?<tete>Unknown column '[^']*')(?<milieu>)(?<queue> in '(?:field list|where clause|order clause|group statement|having clause|on clause)')"
        ."|(?<tete>(?:Column|Field|Table|Unknown column|Unknown table|Check constraint) '[^']*')(?<milieu>.*)(?<queue>)"
        .'|(?<tete>)(?<milieu>.*)(?<queue>)'
        .')$/sD';

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
                .preg_replace(self::CITATION, "'?'", (string) $morceaux['milieu'])
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
