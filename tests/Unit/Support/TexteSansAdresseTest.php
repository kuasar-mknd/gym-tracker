<?php

declare(strict_types=1);

use App\Support\TexteSansAdresse;

/*
 * Toute adresse de courriel d'un texte devient « [adresse masquée] » (#2076) :
 * le message d'une erreur d'envoi, une ligne du journal. Chaque texte est
 * comparé en entier : un masque qui laisserait un morceau de l'adresse, ou
 * qui avalerait autre chose qu'elle, échouerait ici.
 */

it('masque chaque adresse d’un texte', function (string $texte, string $attendu): void {
    expect(TexteSansAdresse::de($texte))->toBe($attendu);
})->with([
    'la réponse d’un serveur qui refuse le destinataire' => [
        'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <Ada.Lovelace@Example.org>: Recipient address rejected: User unknown in local recipient table".',
        'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <[adresse masquée]>: Recipient address rejected: User unknown in local recipient table".',
    ],
    'l’erreur de Symfony Mime, commentaire dans l’adresse' => [
        'Email "ada(x)@example.org" does not comply with addr-spec of RFC 2822.',
        'Email "[adresse masquée]" does not comply with addr-spec of RFC 2822.',
    ],
    'une partie locale entre guillemets' => [
        'Email ""ada lovelace"@example.org" does not comply',
        'Email "[adresse masquée]" does not comply',
    ],
    'un domaine littéral' => [
        'Recipient ada@[192.0.2.1] rejected',
        'Recipient [adresse masquée] rejected',
    ],
    'une adresse dans du JSON' => [
        '{"email":"ada@example.org","nom":"Ada"}',
        '{"email":"[adresse masquée]","nom":"Ada"}',
    ],
    'une adresse entre apostrophes' => [
        "Duplicate entry 'Ada@Example.org' for key 'users.users_email_unique'",
        "Duplicate entry '[adresse masquée]' for key 'users.users_email_unique'",
    ],
    'une adresse internationalisée' => [
        'Refus de jéan@exemple-é.example.org, puis de bob@xn--exemple--9ra.example.org.',
        'Refus de [adresse masquée], puis de [adresse masquée]',
    ],
    'plusieurs adresses sur plusieurs lignes' => [
        "To: ada@example.org\nCc: Bob <bob@example.org>",
        "To: [adresse masquée]\nCc: Bob <[adresse masquée]>",
    ],
]);

it('ne touche pas à ce qui n’est pas une adresse', function (string $texte): void {
    expect(TexteSansAdresse::de($texte))->toBe($texte);
})->with([
    'un chemin de paquet' => ['node_modules/@inertiajs/vue3/dist/index.js'],
    'une mention' => ['Merci @ada, et @bob'],
    'un hôte et son port' => ['Connection could not be established with host "smtp.example.org:587": stream_socket_client(): Unable to connect'],
    'un compte de la base' => ["Access denied for user 'sail'@'%'"],
    'une arobase sans domaine' => ['ada@ et rien'],
]);

/*
 * Un serveur peut ne citer que la partie locale du destinataire : elle se
 * masque quand l'adresse est donnée, quelle que soit sa casse, et seulement
 * comme un mot entier.
 */
it('masque la partie locale des adresses données', function (): void {
    expect(TexteSansAdresse::de('550 5.1.1 Ada.Lovelace... User unknown (ada.lovelace2 est un autre)', ['ada.lovelace@example.org']))
        ->toBe('550 5.1.1 [adresse masquée]... User unknown (ada.lovelace2 est un autre)');
});

it('masque une seule fois ce qu’il a masqué', function (): void {
    $texte = 'Refus de <ada@example.org> et de "bob"@example.org';

    expect(TexteSansAdresse::de(TexteSansAdresse::de($texte)))->toBe(TexteSansAdresse::de($texte));
});

/*
 * Une ligne du journal peut peser plusieurs mégaoctets : chaque mot n'est
 * lu qu'une fois, sans retour en arrière, loin de la limite de PCRE, qui
 * rendrait le texte illisible. Un million de `%41` l'épuisait quand le masque
 * reconnaissait `%40`.
 */
it('lit un mot de plusieurs mégaoctets sans atteindre la limite de PCRE', function (): void {
    $sansAdresse = str_repeat('a', 4_000_000).' '.str_repeat('%41', 1_000_000).' '.str_repeat('"a', 500_000).' '.str_repeat('a.', 1_000_000);
    $adresse = str_repeat('a', 2_000_000).'@example.org';

    expect(TexteSansAdresse::de($sansAdresse))->toBe($sansAdresse)
        ->and(TexteSansAdresse::de($adresse))->toBe(TexteSansAdresse::MASQUE);
});
