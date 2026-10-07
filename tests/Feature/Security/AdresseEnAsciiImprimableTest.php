<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

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
    'un domaine internationalisé sous sa forme Unicode' => ['jean@bücher.example.org'],
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
