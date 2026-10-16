<?php

declare(strict_types=1);

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/*
 * Une adresse de compte reçoit des courriels, que Symfony Mime écrit (#2075).
 *
 * La règle `email` de Laravel juge l'adresse selon la RFC 5322
 * (`RFCValidation` d'Egulias), qui admet un commentaire dans la partie locale
 * (« camille(salle)@example.org ») ; Symfony Mime la refuse comme
 * destinataire (`Address`, `RfcComplianceException`). La mesure ci-dessous
 * dit quelles formes la règle `email` admet et Mime refuse : le commentaire
 * dans la partie locale, seul. La partie locale entre guillemets, le domaine
 * littéral, le domaine sans point, la partie locale de plus de 64 caractères
 * et le commentaire dans le domaine passent l'une et l'autre ; les points
 * consécutifs, aucune. Le courriel part pour de bon, par le transport `array`
 * des tests.
 */

$admisesParLaRegleEmailRefuseesParMime = [
    'un commentaire après la partie locale' => ['camille(salle)@example.org'],
    'un commentaire avant la partie locale' => ['(salle)camille@example.org'],
    'un commentaire imbriqué' => ['camille((salle))@example.org'],
    'un commentaire dans la partie locale et dans le domaine' => ['camille(salle)@example(sud).org'],
];

$refuseesParLaRegleEmailCommeParMime = [
    'des points consécutifs' => ['camille..martin@example.org'],
    'un point en tête de la partie locale' => ['.camille@example.org'],
    'un point au bout du domaine' => ['camille@example.org.'],
    'un commentaire après le domaine' => ['camille@example.org(salle)'],
    'deux @ hors des guillemets' => ['camille@salle@example.org'],
];

$ecritesParMime = [
    'une adresse ordinaire' => ['camille.martin+salle@example.org'],
    'une partie locale entre guillemets' => ['"camille.martin"@example.org'],
    'un @ entre guillemets' => ['"camille@salle"@example.org'],
    'une parenthèse entre guillemets' => ['"camille(salle)"@example.org'],
    'un domaine littéral IPv4' => ['camille@[192.0.2.1]'],
    'un domaine littéral IPv6' => ['camille@[ipv6:2001:db8::1]'],
    'un domaine sans point' => ['camille@localhost'],
    'une partie locale de 65 caractères' => [str_repeat('c', 65).'@example.org'],
    'un commentaire dans le domaine' => ['camille@example(sud).org'],
];

/**
 * Un courriel part-il à cette adresse ? Il part par le courrielleur de
 * l'application, comme les liens, et Symfony Mime refuse d'écrire ce qu'il ne
 * sait pas écrire.
 */
function adresseMessagerieUnCourrielPart(string $adresse): bool
{
    try {
        Mail::raw('Bonjour', static function (Message $message) use ($adresse): void {
            $message->to($adresse)->subject('Essai');
        });
    } catch (RfcComplianceException) {
        return false;
    }

    return true;
}

it('mesure ce que la règle email admet et que Symfony Mime refuse d’écrire', function (string $adresse, bool $laRegleEmailLAdmet, bool $unCourrielPart): void {
    expect([
        'la règle email' => Validator::make(['email' => $adresse], ['email' => ['email']])->passes(),
        'Symfony Mime' => adresseMessagerieUnCourrielPart($adresse),
    ])->toBe([
        'la règle email' => $laRegleEmailLAdmet,
        'Symfony Mime' => $unCourrielPart,
    ]);
})->with([
    ...array_map(static fn (array $cas): array => [...$cas, true, false], $admisesParLaRegleEmailRefuseesParMime),
    ...array_map(static fn (array $cas): array => [...$cas, false, false], $refuseesParLaRegleEmailCommeParMime),
    ...array_map(static fn (array $cas): array => [...$cas, true, true], $ecritesParMime),
]);
