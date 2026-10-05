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
 * tels que le destinataire les reçoit.
 */

/**
 * Les deux courriels que l'application envoie, prêts à rendre.
 *
 * @return array<string, Closure(User): MailMessage>
 */
function courrielsEnFrancaisAEnvoyer(): array
{
    return [
        'vérification de l’adresse' => static fn (User $utilisateur): MailMessage => (new VerifyEmail())->toMail($utilisateur),
        'réinitialisation du mot de passe' => static fn (User $utilisateur): MailMessage => (new ResetPassword('jeton-de-test'))->toMail($utilisateur),
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
        expect($contenu)
            ->toContain('Tous droits réservés.')
            ->toContain('Bonjour !')
            ->toContain('Cordialement,')
            ->not->toContain('All rights')
            ->not->toContain('Regards')
            ->not->toContain('Hello');
    }
})->with(array_keys(courrielsEnFrancaisAEnvoyer()));
