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
    'des ligatures que NFKC allonge' => [str_repeat("\u{FDFA}", 2_731)],
    'des ligatures que NFKC allonge, séparées par des points' => [str_repeat("\u{FDFA}.", 2_048)],
    'des ligatures que NFKC allonge, dans une clé en double' => ['SQLSTATE[23000]: x: 1062 Duplicate entry \''.str_repeat("\u{FDFA}", 2_731)],
]);
