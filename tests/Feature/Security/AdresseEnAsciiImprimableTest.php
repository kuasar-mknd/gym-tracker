<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LienDeChangementDAdresseRecu;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;

/*
 * La colonne `users.email` et son index unique sont en `utf8mb4_unicode_ci`,
 * qui confond une adresse accentuée, un « ß », une lettre pleine chasse avec
 * leur adresse ASCII. L'inscription et le profil acceptaient ces adresses :
 * enregistrée la première, l'une d'elles occupait l'adresse ASCII de quelqu'un
 * d'autre, sans en détenir la boîte, et son titulaire ne pouvait plus ni
 * s'inscrire, ni se connecter par un fournisseur, ni recevoir le lien de
 * réinitialisation, qui partait à l'adresse du compte.
 *
 * Un domaine internationalisé n'en fait plus partie (#2168) : l'inscription et
 * le profil l'écrivent sous sa forme ASCII (`AdresseCanonique`) avant leurs
 * règles, et l'enregistrent au lieu de le refuser. Entre deux adresses ASCII,
 * la collation ne confond que la casse.
 */

/**
 * L'inscription par mot de passe avec cette adresse, telle que la page la poste.
 *
 * @return TestResponse<Response>
 */
function adresseAsciiInscription(string $adresse): TestResponse
{
    return post(route('register'), [
        'name' => 'Jean Dupont',
        'email' => $adresse,
        'password' => 'Un-mot-de-passe-solide-42!',
        'password_confirmation' => 'Un-mot-de-passe-solide-42!',
    ]);
}

function adresseAsciiMessageDeRefus(): string
{
    return 'Le champ adresse e-mail ne doit contenir que des caractères ASCII, sans accent. Un domaine internationalisé s\'écrit sous sa forme ASCII (xn--…).';
}

$adressesQueLaBaseConfondAvecLAscii = [
    'un accent' => ['jéan.dupont@example.org'],
    '« ß » pour « ss »' => ['straße@example.org'],
    'le s long' => ["\u{17F}am@example.org"],
    'la pleine chasse' => ["\u{FF4A}ean@example.org"],
];

/*
 * Un domaine internationalisé tapé en Unicode, tel qu'un champ texte ou un
 * client hors navigateur l'envoie : il s'écrit en ASCII (#2168).
 */
$domainesInternationalisesTapesEnUnicode = [
    'en minuscules' => ['jean@bücher.example.org'],
    'un « b » pleine chasse' => ["jean@\u{FF42}ücher.example.org"],
];

it('refuse à l’inscription une adresse que la base confond avec une adresse ASCII', function (string $adresse): void {
    adresseAsciiInscription($adresse)->assertSessionHasErrors(['email' => adresseAsciiMessageDeRefus()]);

    assertGuest();
    expect(User::query()->count())->toBe(0);

    // La forme ASCII d'un domaine internationalisé, elle, s'inscrit.
    adresseAsciiInscription('jean@xn--bcher-kva.example.org')->assertSessionHasNoErrors();

    expect(User::query()->sole()->email)->toBe('jean@xn--bcher-kva.example.org');
})->with($adressesQueLaBaseConfondAvecLAscii);

it('ne laisse pas une inscription accentuée prendre l’adresse ASCII d’un autre, ni son lien de réinitialisation', function (): void {
    adresseAsciiInscription('jéan.dupont@example.org')->assertSessionHasErrors('email');

    adresseAsciiInscription('jean.dupont@example.org')->assertSessionHasNoErrors();

    $titulaire = User::query()->sole();
    auth()->guard('web')->logout();

    Notification::fake();
    post(route('password.email'), ['email' => 'jean.dupont@example.org'])->assertSessionHasNoErrors();

    Notification::assertSentTo($titulaire, ResetPassword::class);
    expect($titulaire->routeNotificationFor('mail'))->toBe('jean.dupont@example.org');
});

it('refuse au profil une nouvelle adresse hors ASCII, et garde telle quelle l’adresse actuelle d’un compte', function (string $adresse): void {
    $compte = User::factory()->create(['email' => 'camille.martin@example.org']);

    actingAs($compte)
        ->patch(route('profile.update'), ['name' => 'Camille Martin', 'email' => $adresse])
        ->assertSessionHasErrors(['email' => adresseAsciiMessageDeRefus()]);

    expect($compte->refresh()->email)->toBe('camille.martin@example.org');

    /*
     * Un compte ouvert avant la règle sur une adresse hors ASCII enregistre son
     * profil sans en changer : seule une nouvelle adresse est jugée.
     */
    $compteAncien = User::factory()->create(['email' => $adresse]);

    actingAs($compteAncien)
        ->patch(route('profile.update'), ['name' => 'Nouveau nom', 'email' => $adresse])
        ->assertSessionHasNoErrors();

    expect($compteAncien->refresh()->name)->toBe('Nouveau nom')
        ->and($compteAncien->email)->toBe($adresse)
        ->and($compteAncien->hasVerifiedEmail())->toBeTrue();
})->with($adressesQueLaBaseConfondAvecLAscii);

it('enregistre à l’inscription la forme ASCII d’un domaine internationalisé tapé en Unicode', function (string $adresse): void {
    adresseAsciiInscription($adresse)->assertSessionHasNoErrors();

    expect(User::query()->sole()->email)->toBe('jean@xn--bcher-kva.example.org');
})->with($domainesInternationalisesTapesEnUnicode);

it('demande au profil la forme ASCII d’un domaine internationalisé tapé en Unicode', function (string $adresse): void {
    Notification::fake();
    $compte = User::factory()->create(['email' => 'camille.martin@example.org']);

    actingAs($compte)
        ->patch(route('profile.update'), ['name' => 'Camille Martin', 'email' => $adresse, 'current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'lien-de-changement-d-adresse-envoye');

    expect(LienDeChangementDAdresseRecu::pour('jean@xn--bcher-kva.example.org'))->not->toBe('')
        ->and($compte->refresh()->email)->toBe('camille.martin@example.org');
})->with($domainesInternationalisesTapesEnUnicode);

/*
 * L'adresse actuelle du compte reste telle quelle, octet pour octet : un
 * compte enregistré en Unicode avant #2001, que la migration de #2168 laisse
 * (sa forme ASCII est portée par un autre compte), enregistre son nom sans
 * mot de passe. Convertie, l'adresse inchangée passerait pour une nouvelle,
 * et le profil demanderait le mot de passe actuel.
 */
it('garde telle quelle au profil l’adresse actuelle d’un compte en Unicode, et enregistre son nom', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'jean@xn--bcher-kva.example.org']);
    $compteAncien = User::factory()->create(['email' => 'jean@bücher.example.org']);

    actingAs($compteAncien)
        ->patch(route('profile.update'), ['name' => 'Nouveau nom', 'email' => 'jean@bücher.example.org'])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('status');

    expect($compteAncien->refresh()->name)->toBe('Nouveau nom')
        ->and($compteAncien->email)->toBe('jean@bücher.example.org')
        ->and($compteAncien->hasVerifiedEmail())->toBeTrue();

    Notification::assertNothingSent();
});
