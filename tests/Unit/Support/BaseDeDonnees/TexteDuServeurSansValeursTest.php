<?php

declare(strict_types=1);

use App\Support\BaseDeDonnees\TexteDuServeurSansValeurs;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;

/*
 * Le texte d'une erreur du serveur MySQL ou MariaDB cite la valeur refusée ;
 * `TexteDuServeurSansValeurs` la remplace par `'?'` et garde les noms du
 * schéma (#2032). Chaque texte est comparé en entier : un masque qui en
 * laisserait un morceau, ou qui avalerait un nom, échouerait ici.
 *
 * Les textes ont la forme exacte de ceux du serveur MySQL 8.4 de la CI, ou de
 * MariaDB quand elle diffère (une colonne entre accents graves). Un message
 * d'une forme que le masque ne connaît pas perd tout ce qu'il cite.
 *
 * Le serveur écrit certaines valeurs en dernier, puis l'apostrophe qui les
 * ferme : une valeur qui finit par « ' for key 'x » donne un texte qui finit
 * comme une clé en double. La queue n'est gardée qu'au bout de sa forme.
 */

it('remplace par ? la valeur que cite le serveur', function (string $texte, string $attendu): void {
    expect(TexteDuServeurSansValeurs::de($texte))->toBe($attendu);
})->with([
    'une clé en double' => [
        "Duplicate entry 'ada@example.org' for key 'users.users_email_unique'",
        "Duplicate entry '?' for key 'users.users_email_unique'",
    ],
    'une clé en double, sous MariaDB' => [
        "Duplicate entry 'ada@example.org' for key 'users_email_unique'",
        "Duplicate entry '?' for key 'users_email_unique'",
    ],
    'une clé en double dont la valeur porte une apostrophe' => [
        "Duplicate entry 'O'Brien' for key 'users.users_email_unique'",
        "Duplicate entry '?' for key 'users.users_email_unique'",
    ],
    'une clé en double dont la valeur imite une queue' => [
        "Duplicate entry 'o' for key 'secret' et la suite' for key 'users.users_email_unique'",
        "Duplicate entry '?' for key 'users.users_email_unique'",
    ],
    'une clé en double dont la valeur tient sur deux lignes' => [
        "Duplicate entry 'ligne un\nligne deux' for key 'workouts.workouts_name_unique'",
        "Duplicate entry '?' for key 'workouts.workouts_name_unique'",
    ],
    'un entier mal formé' => [
        "Incorrect integer value: 'abc-2032' for column 'user_id' at row 1",
        "Incorrect integer value: '?' for column 'user_id' at row 1",
    ],
    'un entier mal formé, sous MariaDB' => [
        "Incorrect integer value: 'abc-2032' for column `gym`.`workouts`.`user_id` at row 1",
        "Incorrect integer value: '?' for column `gym`.`workouts`.`user_id` at row 1",
    ],
    'une chaîne mal encodée' => [
        "Incorrect string value: '\\xF0\\x9F\\x98\\x80' for column 'notes' at row 3",
        "Incorrect string value: '?' for column 'notes' at row 3",
    ],
    'une date mal formée' => [
        "Incorrect datetime value: 'pas-une-date' for column 'started_at' at row 1",
        "Incorrect datetime value: '?' for column 'started_at' at row 1",
    ],
    'une comparaison qui convertit une valeur' => [
        "Truncated incorrect DOUBLE value: 'ada@example.org'",
        "Truncated incorrect DOUBLE value: '?'",
    ],
    'un fragment de caractère invalide' => [
        "Invalid utf8mb4 character string: '8080'",
        "Invalid utf8mb4 character string: '?'",
    ],
    'une comparaison dont la valeur imite la queue d’une clé en double' => [
        "Truncated incorrect INTEGER value: 'abc' for key 'canari-2032'",
        "Truncated incorrect INTEGER value: '?'",
    ],
    'une comparaison dont la valeur imite une queue de colonne' => [
        "Truncated incorrect DOUBLE value: 'abc' for column 'canari-2032'",
        "Truncated incorrect DOUBLE value: '?'",
    ],
    'une comparaison dont la valeur imite la queue d’une colonne inconnue' => [
        "Truncated incorrect DOUBLE value: 'abc' in 'field list'",
        "Truncated incorrect DOUBLE value: '?'",
    ],
    'une date hors colonne dont la valeur imite une queue' => [
        "Incorrect DATETIME value: 'x' for column 'canari-2032'",
        "Incorrect DATETIME value: '?'",
    ],
    'un fragment de caractère invalide dont la valeur imite une queue' => [
        "Invalid utf8mb4 character string: 'x' for key 'canari-2032'",
        "Invalid utf8mb4 character string: '?'",
    ],
    'une entrée en double d’une liste dont la valeur imite une queue' => [
        "Duplicate entry 'x' for key 'canari-2032'.",
        "Duplicate entry '?'.",
    ],
    'une erreur de syntaxe' => [
        "You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'ada@example.org' at line 1",
        "You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '?' at line 1",
    ],
    'le message de PDO d’une clé en double' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ada@example.org' for key 'users.users_email_unique'",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_email_unique'",
    ],
    'le message de PDO d’un entier mal formé' => [
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'abc-2032' for column 'user_id' at row 1",
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '?' for column 'user_id' at row 1",
    ],
    'le message de PDO d’une comparaison refusée dont la valeur imite une queue' => [
        "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect INTEGER value: 'abc' for key 'canari-2032'",
        "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect INTEGER value: '?'",
    ],
    'le message de PDO d’une connexion refusée' => [
        "SQLSTATE[HY000] [1045] Access denied for user 'sail'@'192.0.2.10' (using password: YES)",
        "SQLSTATE[HY000] [1045] Access denied for user '?' (using password: YES)",
    ],
    'un message de PDO d’une forme inconnue' => [
        "SQLSTATE[HY093]: Invalid parameter number: 'ada@example.org'",
        "SQLSTATE[HY093]: Invalid parameter number: '?'",
    ],
]);

it('garde tel quel un texte qui ne cite que des noms du schéma', function (string $texte): void {
    expect(TexteDuServeurSansValeurs::de($texte))->toBe($texte);
})->with([
    'une colonne nulle' => ["Column 'user_id' cannot be null"],
    'un champ sans défaut' => ["Field 'name' doesn't have a default value"],
    'une colonne inconnue' => ["Unknown column 'nope' in 'field list'"],
    'une table inconnue' => ["Table 'gym.nope' doesn't exist"],
    'une contrainte de vérification' => ["Check constraint 'workouts_chk_1' is violated."],
    'une clé en double sans sa valeur' => ["Duplicate entry for key 'users.users_email_unique'"],
    'une valeur trop longue' => ["Data too long for column 'name' at row 1"],
    'une valeur hors bornes' => ["Out of range value for column 'reps' at row 2"],
    'une valeur coupée' => ["Data truncated for column 'type' at row 1"],
    'un JSON invalide' => ["Invalid JSON text: \"Missing a name for object member.\" at position 1 in value for column 'workouts.notes'."],
    'une clé étrangère' => ['Cannot add or update a child row: a foreign key constraint fails (`gym`.`workouts`, CONSTRAINT `workouts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE)'],
    'un interblocage' => ['Deadlock found when trying to get lock; try restarting transaction'],
    'le message de PDO d’une colonne nulle' => ["SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'user_id' cannot be null"],
    'le message de PDO d’un serveur parti' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
    'le message de PDO d’une connexion impossible' => ['SQLSTATE[HY000] [2002] Connection refused'],
]);

/*
 * Laravel relit le message de l'erreur de PDO pour décider de reconnecter et
 * de rejouer la requête, ou de rejouer une transaction : le masque ne doit
 * lui retirer aucun de ces indices.
 */
it('laisse Laravel reconnaître une connexion perdue dans le texte masqué', function (string $texte): void {
    $masque = TexteDuServeurSansValeurs::de($texte);

    expect(new LostConnectionDetector()->causedByLostConnection(new PDOException($masque)))->toBeTrue();
})->with([
    'un serveur parti' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
    'une connexion perdue en lisant' => ["SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server at 'reading initial communication packet', system error: 0"],
    'une connexion refusée' => ['SQLSTATE[HY000] [2002] Connection refused'],
    'un accès refusé' => ["SQLSTATE[HY000] [1045] Access denied for user 'sail'@'192.0.2.10' (using password: YES)"],
]);

it('laisse Laravel reconnaître un interblocage dans le texte masqué', function (): void {
    $masque = TexteDuServeurSansValeurs::de('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');

    expect(new ConcurrencyErrorDetector()->causedByConcurrencyError(new PDOException($masque)))->toBeTrue();
});

it('masque l’exception de PDO elle-même, sans en changer ni la classe, ni la pile, ni l’origine', function (): void {
    $erreur = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ada@example.org' for key 'users.users_email_unique'");
    $erreur->errorInfo = ['23000', 1062, "Duplicate entry 'ada@example.org' for key 'users.users_email_unique'"];
    $ligne = $erreur->getLine();
    $pile = $erreur->getTraceAsString();

    $masquee = TexteDuServeurSansValeurs::dans($erreur);

    expect($masquee)->toBe($erreur)
        ->and($masquee->getMessage())->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_email_unique'")
        ->and($masquee->errorInfo)->toBe(['23000', 1062, "Duplicate entry '?' for key 'users.users_email_unique'"])
        ->and($masquee->getPrevious())->toBeNull()
        ->and($masquee->getFile())->toBe(__FILE__)
        ->and($masquee->getLine())->toBe($ligne)
        ->and($masquee->getTraceAsString())->toBe($pile)
        ->and((string) $masquee)->not->toContain('ada@example.org');
});

it('laisse un errorInfo absent tel quel', function (): void {
    $erreur = new PDOException("SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'abc-2032' for column 'user_id' at row 1");

    expect(TexteDuServeurSansValeurs::dans($erreur)->errorInfo)->toBeNull()
        ->and($erreur->getMessage())->toBe("SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '?' for column 'user_id' at row 1");
});

/*
 * Une expression régulière peut échouer (limite de retours arrière) : le
 * texte rendu n'est alors rien de l'original, plutôt que l'original entier.
 */
it('rend un texte fixe, sans rien de l’original, quand l’expression échoue', function (): void {
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $masque = TexteDuServeurSansValeurs::de("Duplicate entry 'ada@example.org' for key 'users.users_email_unique'");
    } finally {
        ini_restore('pcre.backtrack_limit');
        ini_restore('pcre.jit');
    }

    expect($masque)->toBe('[texte du serveur illisible]');
});
