<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;

/*
 * Le gabarit publié des courriels termine par « All rights reserved. », que
 * `lang/fr.json` ne traduisait pas : les courriels de vérification de
 * l'adresse et de réinitialisation du mot de passe étaient en français, sauf
 * leur dernière ligne (#1978). Les deux rendus, HTML et texte, sont lus ici
 * tels que le destinataire les reçoit. L'objet aussi : le framework a changé
 * ses clefs (« Verify your email address », « Reset your password »), et la
 * première ligne lue dans la boîte de réception restait anglaise.
 */

/**
 * Les deux courriels que l'application envoie, prêts à rendre.
 *
 * @return array<string, Closure(User): MailMessage>
 */
function courrielsEnFrancaisAEnvoyer(): array
{
    return [
        'vérification de l’adresse' => static fn (User $utilisateur): MailMessage => new VerifyEmail()->toMail($utilisateur),
        'réinitialisation du mot de passe' => static fn (User $utilisateur): MailMessage => new ResetPassword('jeton-de-test')->toMail($utilisateur),
    ];
}

it('rend chaque courriel en français, pied compris', function (string $courriel): void {
    $utilisateur = User::factory()->create();
    $message = courrielsEnFrancaisAEnvoyer()[$courriel]($utilisateur);

    $html = html_entity_decode((string) $message->render(), ENT_QUOTES | ENT_HTML5);
    $texte = html_entity_decode(
        (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data()),
        ENT_QUOTES | ENT_HTML5,
    );

    foreach ([$html, $texte] as $contenu) {
        expect($contenu)->toContain('Tous droits réservés.', 'Bonjour !', 'Cordialement,');

        foreach (['All rights', 'Regards', 'Hello'] as $anglais) {
            expect($contenu)->not->toContain($anglais);
        }
    }
})->with(array_keys(courrielsEnFrancaisAEnvoyer()));

it('donne à chaque courriel un objet français', function (string $courriel, string $objet): void {
    $message = courrielsEnFrancaisAEnvoyer()[$courriel](User::factory()->create());

    expect($message->subject)->toBe($objet);

    foreach (['Verify', 'Reset', 'email'] as $anglais) {
        expect($message->subject)->not->toContain($anglais);
    }
})->with([
    'vérification de l’adresse' => ['vérification de l’adresse', 'Vérifiez votre adresse e-mail'],
    'réinitialisation du mot de passe' => ['réinitialisation du mot de passe', 'Réinitialisez votre mot de passe'],
]);
