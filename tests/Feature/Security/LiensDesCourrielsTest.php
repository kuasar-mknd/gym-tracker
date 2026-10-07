<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\withServerVariables;

/*
 * Les liens des courriels d'authentification se bâtissent sur `APP_URL`, quel
 * que soit l'hôte de la requête qui déclenche l'envoi : ni l'hôte qu'elle
 * annonce, ni celui qu'un proxy de confiance transmet n'y entrent.
 *
 * Les courriels partent vraiment, par le transport `array` de phpunit.xml : le
 * lien est lu dans le message envoyé, tel que l'utilisateur le recevrait. Une
 * notification simulée ne bâtirait son lien qu'à la lecture, hors de la
 * requête qui l'a déclenchée, et ne prouverait rien.
 */

/**
 * L'adresse publique de l'application pendant ces tests.
 */
function liensCourrielsAppUrl(): string
{
    return 'https://gym.example.org';
}

beforeEach(function (): void {
    config(['app.url' => liensCourrielsAppUrl()]);
});

/**
 * Le premier lien absolu du dernier courriel envoyé dont le chemin commence
 * par `$chemin`.
 */
function liensCourrielsDernierLien(string $chemin): string
{
    $transport = Mail::mailer()->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        throw new LogicException('Le test lit les courriels du transport array.');
    }

    $envoi = $transport->messages()->last();

    if (! $envoi instanceof SentMessage) {
        throw new LogicException('Aucun courriel envoyé.');
    }

    $message = $envoi->getOriginalMessage();

    if (! $message instanceof Email) {
        throw new LogicException('Le courriel envoyé n’est pas un message Symfony.');
    }

    $corps = html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES | ENT_HTML5);

    expect(preg_match('#https?://[^\s"<>]+'.preg_quote($chemin, '#').'[^\s"<>]*#', $corps, $trouve))
        ->toBe(1, "aucun lien {$chemin} dans le courriel");

    return $trouve[0] ?? '';
}

/*
 * Les trois façons dont une requête dit son hôte : l'en-tête Host, l'en-tête
 * qu'un pair de confiance transmet, et le témoin, qui n'annonce rien. Chaque
 * cas donne les variables du serveur, les en-têtes et la racine de l'URL.
 */
dataset('liens courriels hotes annonces', [
    'un autre hôte' => [[], [], 'http://piege.example.org'],
    'un hôte transmis par un pair de confiance' => [
        ['REMOTE_ADDR' => '10.0.0.5'],
        ['X-Forwarded-Host' => 'piege.example.org', 'X-Forwarded-Proto' => 'http'],
        '',
    ],
    'le témoin, sans hôte annoncé' => [[], [], ''],
]);

it('bâtit le lien de réinitialisation sur APP_URL, quel que soit l’hôte de la requête', function (array $serveur, array $entetes, string $racine): void {
    $compte = User::factory()->create(['email' => 'membre@example.org']);

    withServerVariables($serveur)->withHeaders($entetes)
        ->post($racine.'/forgot-password', ['email' => $compte->email])
        ->assertSessionHasNoErrors();

    $lien = liensCourrielsDernierLien('/reset-password/');

    expect($lien)->toStartWith(liensCourrielsAppUrl().'/reset-password/')
        ->and($lien)->not->toContain('piege.example.org')
        ->and($lien)->toContain('email=membre%40example.org');
})->with('liens courriels hotes annonces');

it('bâtit le lien de vérification sur APP_URL, quel que soit l’hôte de la requête', function (array $serveur, array $entetes, string $racine): void {
    $compte = User::factory()->unverified()->create();

    actingAs($compte);
    withServerVariables($serveur)->withHeaders($entetes)
        ->post($racine.'/email/verification-notification')
        ->assertRedirect();

    $lien = liensCourrielsDernierLien('/verify-email/');

    expect($lien)->toStartWith(liensCourrielsAppUrl().'/verify-email/'.$compte->id.'/'.sha1($compte->email).'?expires=')
        ->and($lien)->toContain('&signature=')
        ->and($lien)->not->toContain('piege.example.org');
})->with('liens courriels hotes annonces');

it('bâtit sur APP_URL le lien de vérification envoyé à l’inscription, même sous un autre hôte', function (): void {
    post('http://piege.example.org/register', [
        'name' => 'Nouveau membre',
        'email' => 'nouveau@example.org',
        'password' => 'Un-mot-de-passe-2026!',
        'password_confirmation' => 'Un-mot-de-passe-2026!',
    ])->assertRedirect();

    expect(liensCourrielsDernierLien('/verify-email/'))->toStartWith(liensCourrielsAppUrl().'/verify-email/');
});

/*
 * La signature couvre l'URL complète, hôte compris : calculée sur l'URL bâtie
 * sur `APP_URL`, elle se valide quand on suit le lien sous `APP_URL`, et elle
 * se refuse sous un autre hôte.
 */
it('vérifie l’adresse quand on suit le lien sous APP_URL', function (): void {
    $compte = User::factory()->unverified()->create();

    actingAs($compte)->post('http://piege.example.org/email/verification-notification')->assertRedirect();
    $lien = liensCourrielsDernierLien('/verify-email/');

    expect($lien)->toStartWith(liensCourrielsAppUrl().'/');
    actingAs($compte)->get($lien)->assertRedirect();

    expect($compte->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('refuse le lien de vérification suivi sous un autre hôte que APP_URL', function (): void {
    $compte = User::factory()->unverified()->create();

    actingAs($compte)->post('/email/verification-notification')->assertRedirect();
    $lien = liensCourrielsDernierLien('/verify-email/');

    actingAs($compte)
        ->get(str_replace(liensCourrielsAppUrl(), 'http://piege.example.org', $lien))
        ->assertForbidden();

    expect($compte->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('ouvre la page de réinitialisation quand on suit le lien sous APP_URL', function (): void {
    $compte = User::factory()->create();

    post('http://piege.example.org/forgot-password', ['email' => $compte->email])->assertSessionHasNoErrors();

    $lien = liensCourrielsDernierLien('/reset-password/');

    expect($lien)->toStartWith(liensCourrielsAppUrl().'/');
    test()->get($lien)->assertOk();
});
