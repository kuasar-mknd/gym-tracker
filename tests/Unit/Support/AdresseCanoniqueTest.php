<?php

declare(strict_types=1);

use App\Support\AdresseCanonique;

/*
 * Une adresse dont le domaine est internationalisé s'écrit sous deux formes
 * qui désignent la même boîte : Unicode (« bücher ») et ASCII
 * (« xn--bcher-kva »), que la collation de `users.email` ne rapproche pas
 * (#2168). La forme canonique est l'ASCII : seul un domaine qui porte un
 * caractère hors ASCII change, par UTS #46 non transitoire, règles STD3
 * comprises. Restent tels qu'ils sont tapés la partie locale, un domaine
 * ASCII, un domaine que la conversion refuse, une conversion qui ne donne
 * aucun label `xn--` (le domaine n'était internationalisé qu'en apparence,
 * et la collation le rapproche déjà de l'ASCII), et un domaine que l'ancien
 * traitement IDNA, transitoire, écrit autrement (« ß », « ς », U+200C,
 * U+200D) : Chromium envoie « strasse » d'un « straße » tapé, que la
 * collation tient pour le même.
 */

it('écrit en ASCII un domaine internationalisé, quelle que soit sa forme tapée', function (string $adresse, string $attendue): void {
    expect(AdresseCanonique::de($adresse))->toBe($attendue);
})->with([
    'en minuscules' => ['jean@bücher.example.org', 'jean@xn--bcher-kva.example.org'],
    'en capitales, partie locale comprise' => ['Jean@BÜCHER.Example.org', 'Jean@xn--bcher-kva.example.org'],
    'un « b » pleine chasse' => ["jean@\u{FF42}ücher.example.org", 'jean@xn--bcher-kva.example.org'],
    'une partie locale accentuée' => ['hélène.martin@bücher.example.org', 'hélène.martin@xn--bcher-kva.example.org'],
    'une partie locale entre guillemets qui porte un @' => ['"jean@salle"@bücher.example.org', '"jean@salle"@xn--bcher-kva.example.org'],
    'une minuscule glagolitique' => ['jean@ⰰ.example.org', 'jean@xn--tej.example.org'],
    'la capitale glagolitique voisine' => ['jean@Ⰰ.example.org', 'jean@xn--tej.example.org'],
    'un label déjà en ASCII à côté' => ['jean@xn--bcher-kva.müller.example.org', 'jean@xn--bcher-kva.xn--mller-kva.example.org'],
]);

it('laisse l’adresse telle qu’elle est tapée hors d’un domaine internationalisé', function (string $adresse): void {
    expect(AdresseCanonique::de($adresse))->toBe($adresse);
})->with([
    'une partie locale accentuée sous un domaine ASCII' => ['jéan@example.org'],
    'un domaine ASCII en capitales' => ['Jean@EXAMPLE.org'],
    'un domaine déjà en ASCII' => ['jean@xn--bcher-kva.example.org'],
    'un domaine en ASCII et en capitales' => ['JEAN@XN--BCHER-KVA.EXAMPLE.ORG'],
    'un « e » pleine chasse, sans label xn--' => ["jean@\u{FF45}xample.org"],
    'le signe kelvin à la place d’un « k », sans label xn--' => ["jean@\u{212A}iosque.example.org"],
    'un remplissage hangul, que la conversion ignore' => ["jean@example.org\u{3164}"],
    'un « ß »' => ['sam@straße.example.org'],
    'un « ẞ » capital' => ["sam@STRA\u{1E9E}E.example.org"],
    'un sigma final' => ['jean@ς.example.org'],
    'un antiliant sans chasse (U+200C)' => ["jean@bücher\u{200C}.example.org"],
    'un liant sans chasse (U+200D)' => ["jean@bücher\u{200D}.example.org"],
    'un commentaire dans le domaine' => ['jean@bücher.example(sud).org'],
    'un tiret bas, que les règles STD3 refusent' => ['jean@exa_mple.bücher.org'],
    'deux points consécutifs' => ['jean@bücher..example.org'],
    'un label de plus de 63 caractères' => ['jean@bücher'.str_repeat('a', 60).'.example.org'],
    'une espace au bout' => ['jean@bücher.example.org '],
    'un domaine vide' => ['jean@'],
    'sans arobase' => ['bücher.example.org'],
    'un octet hors UTF-8' => ["jean@b\xFFcher.example.org"],
]);

/*
 * Sans les règles STD3, la conversion fait d'un « ＠ » pleine chasse du
 * domaine un « @ » (NFKC) : l'adresse changerait de domaine, et une partie
 * de l'ancien deviendrait une partie locale. Les règles STD3 refusent le
 * « @ » obtenu : la conversion échoue, et l'adresse reste telle quelle.
 */
it('ne fait pas d’un « ＠ » pleine chasse du domaine un « @ »', function (): void {
    $adresse = 'jean@evil.example＠victim.org';

    expect(idn_to_ascii('evil.example＠victim.org', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46))->toBe('evil.example@victim.org')
        ->and(AdresseCanonique::de($adresse))->toBe($adresse)
        ->and(substr_count(AdresseCanonique::de($adresse), '@'))->toBe(1);
});

it('rend une forme qui ne change plus', function (string $adresse): void {
    $forme = AdresseCanonique::de($adresse);

    expect(AdresseCanonique::de($forme))->toBe($forme);
})->with([
    ['Jean@BÜCHER.Example.org'],
    ["jean@\u{FF42}ücher.example.org"],
    ['sam@straße.example.org'],
    ['jéan@example.org'],
]);
