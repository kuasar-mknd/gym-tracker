<?php

declare(strict_types=1);

use App\Services\Diagnostic\ExpurgeLeTexte;
use App\Services\Diagnostic\TexteExpurge;
use Illuminate\Support\Facades\Config;

uses(Tests\TestCase::class);

/*
 * Le texte libre que le diagnostic montre, au détail et sous `TexteBrut`
 * seulement, passe par `ExpurgeLeTexte` (#2032). Un cas par règle, chaque
 * message comparé en entier : la passe de mutation laisserait vivre tout
 * mutant qui ampute un message comparé par inclusion.
 */

/**
 * Le texte expurgé, au budget donné.
 */
function expurgeLeTexte(string $texte, int $budget = ExpurgeLeTexte::BUDGET_PAR_DEFAUT): TexteExpurge
{
    return new ExpurgeLeTexte()($texte, $budget);
}

beforeEach(function (): void {
    Config::set('app.url', 'https://app-canari.example.org');
    Config::set('mail.mailers.smtp.host', 'courrier-canari.example.org');
    Config::set('mail.mailers.smtp.username', 'expediteur-canari');
    Config::set('database.connections.mysql.host', 'base-canari.example.org');
    Config::set('database.connections.mysql.database', 'gym_canari');
    Config::set('database.connections.mysql.username', 'lecteur_canari');
    Config::set('database.redis.default.host', 'cache-canari.example.org');
    Config::set('filesystems.disks.sauvegardes.root', '/srv/sauvegardes-canari');
});

afterEach(function (): void {
    ini_restore('pcre.backtrack_limit');
    ini_restore('pcre.jit');
});

it('coupe l’entrée à 8 Kio avant tout motif', function (): void {
    $expurge = expurgeLeTexte(str_repeat('a', ExpurgeLeTexte::OCTETS_LUS).'jean@example.org', 20_000);

    expect($expurge->texte)->toBe(str_repeat('a', ExpurgeLeTexte::OCTETS_LUS))
        ->and($expurge->invisiblesRetires)->toBe(0);
});

it('coupe l’entrée sur une limite de caractère', function (): void {
    expect(expurgeLeTexte(str_repeat('a', ExpurgeLeTexte::OCTETS_LUS - 1).'éé', 20_000)->texte)
        ->toBe(str_repeat('a', ExpurgeLeTexte::OCTETS_LUS - 1));
});

/*
 * NFKC allonge certains caractères : U+FDFA, trois octets, devient une
 * formule de dix-huit caractères et trente-trois octets. Le texte normalisé
 * est recoupé à 8 Kio, sur une limite de caractère, avant le premier motif :
 * sans quoi les motifs liraient près de onze fois la borne qu'ils supposent.
 */
it('recoupe à 8 Kio le texte que la normalisation allonge', function (): void {
    $formule = 'صلى الله عليه وسلم';

    $expurge = expurgeLeTexte(str_repeat("\u{FDFA}", 2_730), 100_000);

    expect($expurge->texte)->toBe(str_repeat($formule, 248).'صلى ')
        ->and(strlen($expurge->texte))->toBe(ExpurgeLeTexte::OCTETS_LUS - 1)
        ->and(expurgeLeTexte(str_repeat("\u{FDFA}", 300).' jean@example.org', 100_000)->texte)->toBe(str_repeat($formule, 248).'صلى ');
});

it('normalise la pleine chasse en NFKC avant de chercher un courriel', function (): void {
    expect(expurgeLeTexte('écrire à ｊｅａｎ＠ｅｘａｍｐｌｅ．ｏｒｇ, ﬁn')->texte)->toBe('écrire à [courriel], fin');
});

/*
 * Encodé quatre fois, le `%40` reste après les trois passes : le domaine
 * n'en est pas moins marqué comme un hôte.
 */
it('décode les %xx, %40 compris, jusqu’à trois fois, pas plus', function (): void {
    expect(expurgeLeTexte('envoi à jean%40example.org')->texte)->toBe('envoi à [courriel]')
        ->and(expurgeLeTexte('envoi à jean%252540example.org')->texte)->toBe('envoi à [courriel]')
        ->and(expurgeLeTexte('envoi à jean%25252540example.org')->texte)->toBe('envoi à jean%[hôte]')
        ->and(expurgeLeTexte('%EF%BC%A0 pleine chasse encodée')->texte)->toBe('@ pleine chasse encodée');
});

it('retire les caractères d’étiquette et compte ce qu’il retire', function (): void {
    $etiquettes = "Erreur\u{E0001}\u{E0049}\u{E0067}\u{E006E}\u{E006F}\u{E0072}\u{E0065}\u{E007F} de lecture";

    $expurge = expurgeLeTexte($etiquettes);

    expect($expurge->texte)->toBe('Erreur de lecture')
        ->and($expurge->invisiblesRetires)->toBe(8);
});

it('retire les sélecteurs de variante, les espaces de largeur nulle et les remplissages', function (): void {
    $expurge = expurgeLeTexte("a\u{FE0F}b\u{E0100}c\u{200B}d\u{2060}e\u{FEFF}f\u{3164}g\u{115F}h\u{2800}i\u{034F}j\u{202E}k\u{2028}l");

    expect($expurge->texte)->toBe('abcdefghijkl')
        ->and($expurge->invisiblesRetires)->toBe(11);
});

it('retire les séquences ANSI entières et les contrôles, sauf le saut de ligne et la tabulation', function (): void {
    $expurge = expurgeLeTexte("\e[31mrouge\e[0m\r\n\tsuite\x07\e]0;titre\x07fin\x00");

    expect($expurge->texte)->toBe("rouge\n\tsuitefin")
        ->and($expurge->invisiblesRetires)->toBe(22);
});

it('réduit un message d’erreur SQL au SQLSTATE, au code, au verbe, à la table et aux noms', function (): void {
    $message = 'Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 1062 '
        ."Duplicate entry 'Jean d'Artagnan' for key 'users.users_email_unique' "
        .'(Connection: mysql, Host: 192.0.2.10, Port: 3306, Database: gym, '
        ."SQL: insert into `users` (`name`, `email`) values (Jean d'Artagnan, jean@example.org))";

    expect(expurgeLeTexte($message)->texte)->toBe(
        'Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 1062 '
        ."Duplicate entry '?' for key 'users.users_email_unique' (insert `users`)",
    );
});

it('ne garde un nom qu’à la place que lui réserve sa forme', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'une colonne nulle' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'user_id' cannot be null (Connection: mysql, SQL: insert into `workouts` (`user_id`) values (?))",
        "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'user_id' cannot be null (insert `workouts`)",
    ],
    'une valeur mal formée' => [
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'Ève Durand' for column 'user_id' at row 1 (Connection: mysql, SQL: update `workouts` set `user_id` = Ève Durand)",
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '?' for column 'user_id' at row 1 (update `workouts`)",
    ],
    'une valeur qui imite une queue hors de sa forme' => [
        "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect INTEGER value: 'abc' for key 'XK42Q7'",
        "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect INTEGER value: '?'",
    ],
    'une apostrophe seule' => [
        "SQLSTATE[42000]: Syntax error: 1064 near 'secret de Jean",
        "SQLSTATE[42000]: Syntax error: 1064 near '?'",
    ],
    'une citation coupée après une autre' => [
        "SQLSTATE[42000]: Syntax error: 1064 near 'abc' and 'secret de Jean",
        "SQLSTATE[42000]: Syntax error: 1064 near '?'",
    ],
    'une citation coupée dont la valeur porte une apostrophe' => [
        "SQLSTATE[42000]: Syntax error: 1064 near 'secret d'Artagnan",
        "SQLSTATE[42000]: Syntax error: 1064 near '?'",
    ],
    'une clé en double coupée dans sa valeur' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'secret d'Arta",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?'",
    ],
    'une clé en double coupée dans le nom de sa clé' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'secret d'Artagnan' for key 'users.users_em",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_em",
    ],
    'une valeur à apostrophe dans une forme qui finit par sa queue' => [
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'O'Brien' for column 'user_id' at row 1",
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '?' for column 'user_id' at row 1",
    ],
    'un texte complet sans citation' => [
        'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
        'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
    ],
    'une connexion refusée' => [
        'SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: 192.0.2.10, Port: 3306, Database: gym, SQL: select * from `sessions` where `id` = abc)',
        'SQLSTATE[HY000] [2002] Connection refused (select `sessions`)',
    ],
]);

it('marque les valeurs connues de la configuration, relues à chaque appel', function (): void {
    $message = 'Échec vers courrier-canari.example.org pour expediteur-canari, base gym_canari sur base-canari.example.org '
        .'par lecteur_canari, cache cache-canari.example.org, archive /srv/sauvegardes-canari/gym/2026.zip, page https://app-canari.example.org/seances';

    expect(expurgeLeTexte($message)->texte)->toBe(
        'Échec vers [hôte du courrier] pour [utilisateur du courrier], base [base] sur [hôte de la base] '
        .'par [utilisateur de la base], cache [hôte du cache], archive [dossier des sauvegardes]/gym/2026.zip, page https://[hôte de l’application]/seances',
    );

    Config::set('mail.mailers.smtp.host', 'autre-courrier.example.org');

    expect(expurgeLeTexte('vers autre-courrier.example.org')->texte)->toBe('vers [hôte du courrier]');
});

it('ne masque un hôte d’un seul mot que tel qu’il est écrit, et un hôte pointé sans tenir compte de la casse', function (): void {
    Config::set('database.connections.mysql.host', 'mysql');

    expect(expurgeLeTexte('MySQL server has gone away')->texte)->toBe('MySQL server has gone away')
        ->and(expurgeLeTexte('connexion à mysql refusée')->texte)->toBe('connexion à [hôte de la base] refusée');

    Config::set('database.connections.mysql.host', 'base-canari');

    expect(expurgeLeTexte('hôte BASE-CANARI et base-canari')->texte)->toBe('hôte [hôte de la base] et [hôte de la base]');

    Config::set('database.connections.mysql.username', 'lecteur_canari');
    Config::set('database.connections.mysql.database', 'gym_canari');

    expect(expurgeLeTexte('par LECTEUR_CANARI sur Gym_Canari')->texte)->toBe('par [utilisateur de la base] sur [base]');

    Config::set('database.connections.mysql.host', 'Base-Canari');

    expect(expurgeLeTexte('hôte base-canari et BASE-CANARI')->texte)->toBe('hôte [hôte de la base] et [hôte de la base]');

    Config::set('database.connections.mysql.host', 'base-canari.example.org');

    expect(expurgeLeTexte('driver://BASE-CANARI.EXAMPLE.ORG:3306/gym ; dsn host=base-canari.example.org;dbname=gym')->texte)
        ->toBe('driver://[hôte de la base]:3306/gym ; dsn host=[hôte de la base];dbname=gym');
});

it('ne cherche pas une valeur de moins de quatre caractères, ni au milieu d’un mot', function (): void {
    Config::set('database.connections.mysql.username', 'abc');
    Config::set('database.connections.mysql.database', 'gymz');

    expect(expurgeLeTexte('abc lit gymz, pas gymzone ni xgymz')->texte)->toBe('abc lit [base], pas gymzone ni xgymz');
});

/*
 * Une chaîne de requête et un fragment vont jusqu'au prochain blanc, virgule
 * comprise : s'arrêter à une virgule laisserait passer ce qui la suit.
 */
it('marque les courriels, les adresses IP, les hôtes, les chaînes de requête et les fragments', function (): void {
    $message = 'De jean.dupont+test@example.org depuis 203.0.113.7 et 2001:db8::7, '
        .'page https://gym.example.org:8443/seances/12?note=Jean&jeton=x#ancre, hôte smtp.example.org';

    expect(expurgeLeTexte($message)->texte)->toBe(
        'De [courriel] depuis [adresse IP] et [adresse IP], page https://[hôte]/seances/12?[requête]#[fragment] hôte [hôte]',
    );
});

/*
 * Une adresse se reconnaît à ses quatre nombres, pas à ce qui l'entoure :
 * un point qui finit la phrase, un deux-points, des points de suspension, le
 * préfixe d'une adresse IPv4 écrite en IPv6. Seul un nombre collé à elle,
 * ou un cinquième nombre pointé, en fait autre chose.
 */
it('marque une adresse IP quel que soit son voisin', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'en fin de phrase' => ['Trop de requêtes depuis 203.0.113.7.', 'Trop de requêtes depuis [adresse IP].'],
    'après un deux-points' => ['client ip:203.0.113.7 refusé', 'client ip:[adresse IP] refusé'],
    'après des points de suspension' => ['vu...203.0.113.7 hier', 'vu...[adresse IP] hier'],
    'une plage' => ['plage 203.0.113.7-203.0.113.9 bloquée', 'plage [adresse IP]-[adresse IP] bloquée'],
    'une adresse IPv4 écrite en IPv6' => ['client ::ffff:203.0.113.7 refusé', 'client [adresse IP] refusé'],
    'une adresse IPv6 en fin de phrase' => ['Trop de requêtes depuis 2001:db8::7.', 'Trop de requêtes depuis [adresse IP].'],
    'cinq nombres pointés' => ['version 1.2.3.4.5 et 1203.0.113.7', 'version 1.2.3.4.5 et 1203.0.113.7'],
]);

/*
 * Un hôte se reconnaît à son dernier label quand il n'en a que deux (une
 * extension connue, un code de pays), sauf après `//`, `@` ou « host » ; à
 * partir de trois labels, tout dernier label alphabétique, même quand un
 * label du milieu est une extension connue ou un code de pays. Un nom de
 * route à deux labels, un index et un fichier restent.
 */
it('marque un hôte dont l’extension n’est pas dans la liste', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'trois labels' => ['smtp.salle-canari.systems refusé', '[hôte] refusé'],
    'deux labels dans une URL' => ['page https://salle-canari.shop/seances', 'page https://[hôte]/seances'],
    'deux labels après « host »' => ['Connection could not be established with host salle-canari.email :stream', 'Connection could not be established with host [hôte] :stream'],
    'un nom internationalisé' => ['smtp.salle-réservée.systems refusé', '[hôte] refusé'],
    'un label du milieu qui est une extension connue, et un port' => ['connexion refusée par relais.us.salle-canari.systems:587', 'connexion refusée par [hôte]'],
    'un label du milieu de deux lettres' => ['hôte smtp.de.salle-canari.email injoignable', 'hôte [hôte] injoignable'],
    'un label du milieu dans une URL' => ['page https://api.io.salle-canari.systems/v1', 'page https://[hôte]/v1'],
    'un label du milieu nommé example' => ['connexion à smtp.example.systems', 'connexion à [hôte]'],
    'en fin de phrase' => ['Envoi refusé par smtp.example.org.', 'Envoi refusé par [hôte].'],
    'une route, un index, un fichier' => [
        'route workouts.show, index users.users_email_unique, fichier jquery.min.js',
        'route workouts.show, index users.users_email_unique, fichier jquery.min.js',
    ],
]);

it('laisse une heure, une version, un appel et un fichier', function (): void {
    $message = 'À 12:30:45, version 1.6.1, App\\Http\\Controllers\\WorkoutsController::show dans app.js et Connection.php, values (?, ?), #0 {main}';

    expect(expurgeLeTexte($message)->texte)->toBe($message);
});

it('remplace par [secret] les jetons, les JWT et les longues suites', function (): void {
    $jeton = 'gtdiag_'.str_repeat('a1', 6).'_'.str_repeat('Ab3', 16);
    $message = 'Authorization: Bearer abc.def-ghi, jeton '.$jeton
        .', jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl'
        .', hex a3f9c1e7b2d4a6f8c0e1b3d5f7a9c2e4b6d8f0a1'
        .', clé Zm9vYmFyYmF6UXV4UXV1eFRlc3RTZWNyZXQxMjM0NTY3ODkw'
        .', chiffré dG9w/c2VjcmV0/YWJjZGVmZ2hpams+bG1ub3BxcnN0dXZ3eHl6'
        .', migration 2026_09_06_115255_create_filament_exceptions_table';

    expect(expurgeLeTexte($message)->texte)->toBe(
        'Authorization: Bearer [secret] jeton [secret], jwt [secret], hex [secret], clé [secret], chiffré [secret], '
        .'migration 2026_09_06_115255_create_filament_exceptions_table',
    );
});

/*
 * Le jeton d'un lien de réinitialisation ou de vérification est dans le
 * chemin de la page : la table des exceptions garde le chemin brut, et une
 * erreur navigateur la page. Une suite hexadécimale est un secret, qu'une
 * barre oblique, un signe égal, un tiret ou un souligné la précède ; une
 * lettre ou un chiffre collés en font un mot.
 */
it('remplace par [secret] une longue suite hexadécimale quel que soit son voisin', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'le chemin d’un lien de réinitialisation' => [
        'reset-password/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        'reset-password/[secret]',
    ],
    'la page d’un lien de réinitialisation' => [
        'https://gym.example.org/reset-password/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        'https://[hôte]/reset-password/[secret]',
    ],
    'la page d’un lien de vérification' => [
        'https://gym.example.org/verify-email/12/2fd4e1c67a2d28fced849ee1bb76e7391b93eb12',
        'https://[hôte]/verify-email/12/[secret]',
    ],
    'après un signe égal' => ['Clé refusée : api_key=c0ffee5ca1ab1e0ddba11deadbeefcafe0123456', 'Clé refusée : api_key=[secret]'],
    'après un tiret ou un souligné' => [
        'jeton-c0ffee5ca1ab1e0ddba11deadbeefcafe0123456 et sk_c0ffee5ca1ab1e0ddba11deadbeefcafe0123456',
        'jeton-[secret] et sk_[secret]',
    ],
    'au milieu d’un secret en base64' => ['clé Zm9vYmFy+c0ffee5ca1ab1e0ddba11deadbeefcafe0123456+YmF6 refusée', 'clé [secret] refusée'],
    'avec le préfixe 0x' => ['clé 0xc0ffee5ca1ab1e0ddba11deadbeefcafe0123456 refusée', 'clé [secret] refusée'],
    'avec le préfixe 0X, en capitales' => ['clé=0XC0FFEE5CA1AB1E0DDBA11DEADBEEFCAFE0123456.', 'clé=[secret].'],
    'collée à un mot' => ['cle cachec0ffee5ca1ab1e0ddba11deadbeefcafe0123456', 'cle cachec0ffee5ca1ab1e0ddba11deadbeefcafe0123456'],
    'un préfixe 0x collé à un mot' => ['cle cache0xc0ffee5ca1ab1e0ddba11deadbeefcafe0123456', 'cle cache0xc0ffee5ca1ab1e0ddba11deadbeefcafe0123456'],
]);

/*
 * Un secret en base64 est masqué qu'un deux-points ou un signe égal le
 * précède ; un chemin ou une valeur sans forme de secret reste.
 */
it('masque un secret en base64 derrière un signe égal comme derrière un deux-points', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'après un deux-points' => ['cle: Zm9vYmFyYmF6UXV4UXV1eFRlc3RTZWNyZXQxMjM0NTY3ODkw', 'cle: [secret]'],
    'après un signe égal' => ['CLE=Zm9vYmFyYmF6UXV4UXV1eFRlc3RTZWNyZXQxMjM0NTY3ODkw', 'CLE=[secret]'],
    'après un signe égal et des barres obliques' => ['CLE=dG9w/c2VjcmV0/YWJjZGVmZ2hpams+bG1ub3BxcnN0dXZ3eHl6', 'CLE=[secret]'],
    'un chemin après un signe égal' => ['chemin=app/Http/Controllers/Api/V1/WorkoutsController.php', 'chemin=app/Http/Controllers/Api/V1/WorkoutsController.php'],
    'un nom de migration après un signe égal' => ['fichier=2026_09_06_115255_create_filament_exceptions_table', 'fichier=2026_09_06_115255_create_filament_exceptions_table'],
]);

/*
 * Les identifiants d'une URL se taisent même quand l'hôte n'a pas de point :
 * le motif des courriels et celui des hôtes ne les voient pas.
 */
it('masque les identifiants d’une URL dont l’hôte n’a pas de point', function (string $identifiants, string $suite, string $attendu): void {
    expect(expurgeLeTexte(sprintf('connexion à %s://%s@%s', 'amqp', $identifiants, $suite))->texte)->toBe(sprintf('connexion à amqp://%s', $attendu));
})->with([
    'un mot de passe seul' => [':'.'motdepasse', 'db/app', '[identifiants]@db/app'],
    'un compte et un mot de passe' => ['sail:'.'motdepasse', 'db/app', '[identifiants]@db/app'],
    'un mot de passe qui porte un arobase' => ['sail:p'.'@ss', 'db/app', '[identifiants]@db/app'],
    'un hôte et un port' => ['sail:'.'motdepasse', 'broker:5672/0', '[identifiants]@broker:5672/0'],
]);

it('ne laisse pas les identifiants d’une URL déborder sur le texte qui suit', function (): void {
    $message = 'page '.'https'.'://example.org:8443 puis jean@example.org';

    expect(expurgeLeTexte($message)->texte)->toBe('page https://[hôte] puis [courriel]');
});

/*
 * Un secret en base64 qui porte `/` sans `+` ni `=` ressemble à un chemin :
 * seul le nom qui le précède le désigne.
 */
it('masque la valeur qui suit un nom de secret', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'une clé d’accès, au signe égal' => [
        'AWS_SECRET_ACCESS_KEY='.implode('/', ['wJalrXUtnFEMI', 'K7MDENG', 'bPxRfiCYEXAMPLEKEY']),
        'AWS_SECRET_ACCESS_KEY=[secret]',
    ],
    'une clé de JSON' => ['{"api_key":"'.implode('/', ['abcd1234', 'efgh5678', 'ijkl']).'","x":1}', '{"api_key":"[secret]","x":1}'],
    'un jeton avec deux-points et blancs' => ['token :  '.implode('/', ['abcd1234', 'efgh5678']), 'token :  [secret]'],
    'une signature, un mot de passe' => ['signature=abcdefgh12345678 password=hunter2hunter2', 'signature=[secret] password=[secret]'],
    'un paramètre court' => ['password = ?', 'password = ?'],
    'une valeur de huit caractères' => ['password=abcdefgh', 'password=[secret]'],
    'une valeur de sept caractères' => ['password=abcdefg', 'password=abcdefg'],
    'une valeur entre apostrophes' => ["pwd: 'abcdefgh' fin", "pwd: '[secret]' fin"],
    'un nom précédé de lettres' => ['MY_APP-api-key=abcdefgh12', 'MY_APP-api-key=[secret]'],
    'une valeur trop courte' => ['password=abc', 'password=abc'],
    'une clé de contrainte SQL' => ["for key 'users.email_unique'", "for key 'users.email_unique'"],
    'un mot qui finit par key sans séparateur' => ['monkey business: app/Models/User', 'monkey business: app/Models/User'],
]);

/*
 * Un courriel écrit avec une entité HTML (`&#64;`) ou une barre oblique
 * inverse devant l'arobase (`\@`) se reconnaît après décodage ; un caractère
 * invisible écrit en entité part avec les autres.
 */
it('décode les entités HTML, jusqu’à trois fois, avant de masquer', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'un arobase en entité décimale' => ['jean&#64;example.org', '[courriel]'],
    'un arobase en entité hexadécimale' => ['jean&#x40;example.org', '[courriel]'],
    'un arobase en entité nommée' => ['jean&commat;example.org', '[courriel]'],
    'une entité écrite deux fois' => ['jean&amp;#64;example.org', '[courriel]'],
    'une entité écrite trois fois' => ['jean&amp;amp;#64;example.org', '[courriel]'],
    'une entité sous un pourcentage' => ['jean&#37;40example.org', '[courriel]'],
    'une entité décodée en pleine chasse' => ['jean&#xFF20;example.org', '[courriel]'],
    'une barre oblique devant l’arobase' => ['jean\\@example.org', '[courriel]'],
    'une barre oblique et une entité' => ['jean\\&#64;example.org', '[courriel]'],
    'un invisible en entité' => ['jean&#8203;@example.org', '[courriel]'],
    'une apostrophe en entité' => ['l&#39;haltère', 'l\'haltère'],
]);

/*
 * Les identifiants d'une URL vont jusqu'au dernier `@` de leur mot, quel que
 * soit ce que le mot de passe contient (`/`, `?`, `#`, `@`), brut ou écrit en
 * `%xx`, et même quand il porte un blanc écrit autrement qu'en clair (`%20`,
 * `%C2%A0`, `&#32;`, `&nbsp;`…). Ne sont pas couverts : un blanc écrit en clair
 * (il finit le mot), un deux-points encodé (`%3A`) entre le compte et le mot
 * de passe, un mot de passe de plus de 256 caractères.
 */
it('masque les identifiants d’une URL dont le mot de passe porte un caractère de chemin', function (string $motDePasse): void {
    $message = sprintf('connexion à %s://%s:%s@%s', 'amqp', 'compte', $motDePasse, 'broker:5672/0');

    expect(expurgeLeTexte($message)->texte)->toBe('connexion à amqp://[identifiants]@broker:5672/0');
})->with([
    'une barre oblique' => ['pa/ssword'],
    'un point d’interrogation' => ['pa?ssword'],
    'un dièse' => ['pa#ssword'],
    'une barre oblique et un point d’interrogation encodés' => ['pa%2Fss%3Fw'],
    'un dièse encodé' => ['pa%23ssword'],
    'un blanc encodé' => ['pa%20ssword'],
    'un blanc encodé deux fois' => ['pa%2520ssword'],
    'un espace insécable encodé' => ['pa%C2%A0ssword'],
    'un espace cadratin encodé' => ['pa%E2%80%83ssword'],
    'un blanc en entité décimale' => ['pa&#32;ssword'],
    'un blanc en entité décimale sans point-virgule' => ['pa&#32ssword'],
    'une tabulation en entité' => ['pa&#9;ssword'],
    'un blanc en entité hexadécimale' => ['pa&#x20;ssword'],
    'un espace insécable en entité nommée' => ['pa&nbsp;ssword'],
    'une tabulation encodée' => ['pa%09ssword'],
    'un arobase' => ['pa@ssword'],
]);

it('arrête les identifiants d’une URL au blanc, à la guillemet et à la fin de ligne', function (): void {
    $url = sprintf('%s://%s:%s@%s', 'amqp', 'compte', 'secret', 'broker/0');

    expect(expurgeLeTexte($url."\nécrire à jean@example.org")->texte)->toBe("amqp://[identifiants]@broker/0\nécrire à [courriel]")
        ->and(expurgeLeTexte('"'.$url.'" puis jean@example.org')->texte)->toBe('"amqp://[identifiants]@broker/0" puis [courriel]');
});

/*
 * Un octet décodé qui ne forme pas d'UTF-8 valide est remplacé : le reste du
 * texte se lit, et le champ n'est pas `[illisible]`.
 */
it('remplace un octet décodé invalide sans rendre le champ illisible', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'un octet isolé' => ['texte %FF fin', 'texte ? fin'],
    'un é en Latin-1 dans une requête' => ['GET /recherche?q=%E9t%E9 404', 'GET /recherche?[requête] 404'],
    'un é en Latin-1 dans un chemin' => ['chemin /tmp/caf%E9.txt', 'chemin [chemin]?[requête]'],
    'un octet isolé décodé au deuxième passage' => ['texte %25FF fin', 'texte ? fin'],
    'un octet isolé dans des identifiants d’URL' => [sprintf('%s://%s:%s@%s fin', 'amqp', 'compte', 'pa%FFss', 'broker/0'), 'amqp://[identifiants]@broker/0 fin'],
]);

it('remplace un début de caractère que la borne de 8 Kio coupe', function (): void {
    $texte = expurgeLeTexte(str_repeat('a', 8_183).'%C3%A9%C3', 20_000)->texte;

    expect($texte)->toBe(str_repeat('a', 8_183).'é?');
});

/*
 * Deux ambiguïtés acceptées : un `@` dans le chemin d'une URL à port se lit
 * comme la fin d'identifiants, et un nom de secret emporte ce qui suit sa
 * valeur jusqu'au blanc.
 */
it('accepte de trop masquer quand la forme est ambiguë', function (): void {
    expect(expurgeLeTexte('https://example.org:8443/a@b')->texte)->toBe('https://[identifiants]@b')
        ->and(expurgeLeTexte('x password=abcdefgh&user=jean fin')->texte)->toBe('x password=[secret] fin');
});

/*
 * Le masque d'un nom de secret lit des caractères, pas des octets : une
 * valeur multioctet ne se coupe jamais au milieu d'un caractère.
 */
it('masque une valeur multioctet sans couper un caractère', function (): void {
    $texte = expurgeLeTexte('Erreur key=a'.str_repeat('é', 3_000));

    expect($texte->texte)->toBe('Erreur key=[secret]');
});

it('masque la valeur d’un nom de secret quelle que soit la mise en forme du texte', function (string $message, string $attendu): void {
    expect(expurgeLeTexte($message)->texte)->toBe($attendu);
})->with([
    'du JSON échappé' => ['{\"password\":\"abcdefgh12\"}', '{\"password\":\"[secret]"}'],
    'du JSON' => ['{"password":"abcdefgh12","x":1}', '{"password":"[secret]","x":1}'],
    'un print_r' => ['[password] => abcdefgh12', '[password] => [secret]'],
    'un var_export' => ["'password' => 'abcdefgh12',", "'password' => '[secret]',"],
    'une valeur entre guillemets avec des blancs' => ['password="un mot de passe long" fin', 'password="[secret]" fin'],
    'une valeur entre apostrophes avec des blancs' => ["pwd = 'un mot de passe' fin", "pwd = '[secret]' fin"],
    'une valeur qui porte une virgule' => ['password=abcdefgh,TAIL fin', 'password=[secret] fin'],
    'une valeur qui porte un point-virgule et une parenthèse' => ['(token=abcdefgh;TAIL) fin', '(token=[secret] fin'],
    'sept caractères, comme prévu' => ['pwd=hunter2', 'pwd=hunter2'],
    'un paramètre court' => ['password = ?', 'password = ?'],
    'une clé de contrainte' => ["for key 'users.email_unique'", "for key 'users.email_unique'"],
    'un nom qui se termine par key, accepté' => ['monkey=bananasplit', 'monkey=[secret]'],
]);

/*
 * Une entité numérique sans point-virgule se décode comme le fait un
 * navigateur.
 */
it('décode une entité numérique sans point-virgule avant de masquer', function (string $message): void {
    expect(expurgeLeTexte($message)->texte)->toBe('[courriel]');
})->with([
    'décimale' => ['jean&#64example.org'],
    'hexadécimale' => ['jean&#x40mail.example.org'],
    'décimale suivie d’un mot' => ['jean&#64mail.example.org'],
]);

it('ramène un chemin du dépôt à sa racine et marque les autres chemins absolus', function (): void {
    $message = 'Dans '.base_path('app/Models/User.php').':12, lu /etc/ssl/private/cle.pem et (/var/lib/donnees/a.db)';

    expect(expurgeLeTexte($message)->texte)->toBe('Dans app/Models/User.php:12, lu [chemin] et ([chemin])');
});

it('coupe au budget du champ, sur une limite de caractère, et le dit', function (): void {
    expect(expurgeLeTexte(str_repeat('é', 20), 30)->texte)->toBe(str_repeat('é', 8).ExpurgeLeTexte::MARQUE_DE_COUPURE)
        ->and(expurgeLeTexte('court', 30)->texte)->toBe('court')
        ->and(expurgeLeTexte(str_repeat('a', 30), 30)->texte)->toBe(str_repeat('a', 30));
});

it('rend [illisible] quand une expression échoue, jamais le texte d’origine', function (): void {
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    $expurge = expurgeLeTexte("Mot de passe de jean@example.org : 's3cr3t' depuis 203.0.113.7");

    expect($expurge)->toEqual(new TexteExpurge(ExpurgeLeTexte::ILLISIBLE, 0));
});

/*
 * La meilleure de cinq mesures : une expression qui revient en arrière sans
 * fin dépasse la borne à chaque passage, une machine chargée (la suite
 * parallèle de la CI, un autre processus) n'en retarde que certains. Vu le
 * 2026-10-14 : 53, 55 et 91 ms pour un traitement d'une milliseconde, sous
 * une charge de 28 sur quatre cœurs.
 */
it('traite une chaîne adverse de 8 Kio en moins de 50 ms', function (string $adverse): void {
    expect(strlen($adverse))->toBeGreaterThanOrEqual(ExpurgeLeTexte::OCTETS_LUS);

    $mesures = array_map(static function () use ($adverse): array {
        $debut = hrtime(true);
        $expurge = expurgeLeTexte($adverse);

        return ['millisecondes' => (hrtime(true) - $debut) / 1_000_000, 'texte' => $expurge->texte];
    }, range(1, 5));

    expect(min(array_column($mesures, 'millisecondes')))->toBeLessThan(50.0)
        ->and(array_unique(array_column($mesures, 'texte')))->toHaveCount(1)
        ->and($mesures[0]['texte'])->not->toBe(ExpurgeLeTexte::ILLISIBLE);
})->with([
    'des lettres' => [str_repeat('a', 8_192)],
    'des arobases' => [str_repeat('a@', 4_096)],
    'des points' => [str_repeat('a.', 4_096)],
    'des chiffres pointés' => [str_repeat('1.', 4_096)],
    'des deux-points' => [str_repeat('a:', 4_096)],
    'des apostrophes' => ['SQLSTATE[23000]: x: 1062 Duplicate entry '.str_repeat("'a", 4_096)],
    'des pourcentages' => [str_repeat('%25', 2_731)],
    'des débuts de JWT' => [str_repeat('eyJ', 2_731)],
    'du base64 sans fin' => [str_repeat('Ab3+', 2_048)],
    'des barres obliques' => [' '.str_repeat('/a', 4_096)],
    'des requêtes' => [str_repeat('a?', 4_096)],
    'des marques SQL' => [str_repeat('SQLSTATE[', 911)],
    'des queues de clé en double' => ['SQLSTATE[23000]: x: 1062 Duplicate entry '.str_repeat(" for key 'a", 820)],
    'des queues de valeur mal formée' => ['SQLSTATE[HY000]: x: 1366 Incorrect integer value:'.str_repeat(" for column 'a' at row 1x", 330)],
    'des en-têtes de PDO' => ['SQLSTATE[HY000]: '.str_repeat('x: 1 ', 1_640)],
    'des étiquettes' => [str_repeat("\u{E0041}", 2_048)],
    'des séquences ANSI ouvertes' => [str_repeat("\e]", 4_096)],
    'de l’hexadécimal mêlé' => [str_repeat('a1', 4_096)],
    'des préfixes 0x' => [str_repeat('0x', 4_096)],
    'des groupes IPv6' => [str_repeat('a:a:', 2_048)],
    'des labels d’hôte' => [str_repeat('ab.', 2_731)],
    'des labels accentués' => [str_repeat('éb.', 2_731)],
    'des « host » enchaînés' => [str_repeat('host a.', 1_171)],
    'des adresses IPv4 enchaînées' => [str_repeat('1.2.3.4.', 1_024)],
    'des adresses IPv4 écrites en IPv6' => [str_repeat('::ffff:1.2.3.', 631)],
    'de l’hexadécimal entre des tirets' => [str_repeat('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0-', 200)],
    'des courriels enchaînés' => [str_repeat('a.b@c.', 1_366)],
    'des débuts d’URL' => [str_repeat('a://', 2_048)],
    'des identifiants d’URL sans fin' => ['a://'.str_repeat('b', 8_188)],
    'des noms de secret' => [str_repeat('key=', 2_048)],
    'des noms de secret préfixés' => [str_repeat('a_key:', 1_366)],
    'des noms de secret sans valeur' => [str_repeat('token ', 1_366)],
    'des identifiants d’URL à mots de passe' => [str_repeat('a://b:', 1_366)],
    'des identifiants d’URL sans arobase' => ['a://b:'.str_repeat('c/', 4_094)],
    'des identifiants d’URL encodés' => [str_repeat('a://b:%20', 911)],
    'des noms de secret entre guillemets' => [str_repeat('password="', 820)],
    'des noms de secret entre apostrophes sans fin' => ["password => '".str_repeat('a', 8_200)],
    'des noms de secret échappés' => [str_repeat('key\\":\\"', 1_024)],
    'des entités sans point-virgule' => [str_repeat('&#64', 2_048)],
    'des identifiants d’URL à blancs encodés en UTF-8' => [str_repeat('a://b:%C2%A0', 700)],
    'des identifiants d’URL à blancs en entité' => [str_repeat('a://b:&#32;', 746)],
    'des octets invalides décodés' => [str_repeat('%FF%E9', 1_366)],
    'des entités' => [str_repeat('&amp;', 1_639)],
    'des entités d’arobase' => [str_repeat('&#64;', 1_639)],
    'des barres obliques inverses' => [str_repeat('\\', 8_192)],
    'des ligatures que NFKC allonge' => [str_repeat("\u{FDFA}", 2_731)],
    'des ligatures que NFKC allonge, séparées par des points' => [str_repeat("\u{FDFA}.", 2_048)],
    'des ligatures que NFKC allonge, dans une clé en double' => ['SQLSTATE[23000]: x: 1062 Duplicate entry \''.str_repeat("\u{FDFA}", 2_731)],
]);
