<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * Une liaison prouvée ouvre le compte par l'identité du fournisseur seule
 * (`ResolveSocialUserAction`). La preuve vaut pour l'adresse du compte et pour
 * l'identité sur lesquelles elle a été faite : un changement de l'une ou de
 * l'autre l'efface (`OublieLaPreuveDeSaLiaison`). Sans quoi le titulaire d'une
 * identité liée à un compte qui passe à une autre adresse, et peut-être à une
 * autre personne par la réinitialisation du mot de passe, garderait la clé de
 * ce compte.
 */

/**
 * Un compte relié à Google, dont la liaison a été prouvée.
 */
function preuveDeLaLiaisonLeCompte(): User
{
    return User::factory()->create([
        'email' => 'camille.martin@example.org',
        'provider' => 'google',
        'provider_id' => 'identite-google',
        'liaison_prouvee_le' => Carbon::parse('2026-03-01 10:00:00'),
    ]);
}

beforeEach(function (): void {
    Notification::fake();
});

it('efface la preuve quand l’adresse du compte change, même si l’écriture la repose', function (string $nouvelleAdresse, bool $preuveDansLaMemeEcriture): void {
    $compte = preuveDeLaLiaisonLeCompte();

    $compte->forceFill([
        'email' => $nouvelleAdresse,
        ...($preuveDansLaMemeEcriture ? ['liaison_prouvee_le' => Carbon::parse('2026-10-05 08:00:00')] : []),
    ])->save();

    expect($compte->refresh()->liaison_prouvee_le)->toBeNull()
        ->and($compte->provider_id)->toBe('identite-google');
})->with([
    'une autre adresse' => ['dominique.petit@example.org', false],
    'la seule casse' => ['Camille.Martin@example.org', false],
    'avec la preuve dans la même écriture' => ['dominique.petit@example.org', true],
]);

it('efface la preuve quand la liaison change sans elle', function (?string $fournisseur, ?string $identifiant): void {
    $compte = preuveDeLaLiaisonLeCompte();

    $compte->forceFill(['provider' => $fournisseur, 'provider_id' => $identifiant])->save();

    expect($compte->refresh()->liaison_prouvee_le)->toBeNull();
})->with([
    'une autre identité' => ['google', 'autre-identite-google'],
    'un autre fournisseur' => ['github', 'identite-google'],
    'la liaison retirée' => [null, null],
]);

it('garde la preuve posée avec la liaison, et celle d’un compte dont seul le reste change', function (Closure $changer, string $preuveAttendue): void {
    $compte = preuveDeLaLiaisonLeCompte();

    $changer($compte);
    $compte->save();

    expect($compte->refresh()->liaison_prouvee_le?->toDateTimeString())->toBe($preuveAttendue);
})->with([
    'une liaison prouvée dans la même écriture' => [
        static fn (User $compte): User => $compte->forceFill(['provider_id' => 'autre-identite-google', 'liaison_prouvee_le' => Carbon::parse('2026-10-05 08:00:00')]),
        '2026-10-05 08:00:00',
    ],
    'le nom' => [static fn (User $compte): User => $compte->forceFill(['name' => 'Camille Durand']), '2026-03-01 10:00:00'],
    'le mot de passe' => [static fn (User $compte): User => $compte->forceFill(['password' => 'Un-autre-mot-de-passe-42!']), '2026-03-01 10:00:00'],
    'la vérification de l’adresse' => [static fn (User $compte): User => $compte->forceFill(['email_verified_at' => null]), '2026-03-01 10:00:00'],
]);
