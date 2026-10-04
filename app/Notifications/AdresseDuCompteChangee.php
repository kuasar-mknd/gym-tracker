<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient l'ANCIENNE adresse d'un compte que l'adresse de connexion a changé.
 *
 * Elle part par une route à la demande (`Notification::route('mail', …)`) : le
 * compte porte déjà la nouvelle adresse, et c'est précisément l'ancienne, celle
 * de la personne qui pourrait ne pas être à l'origine du changement, qu'il faut
 * atteindre. Elle dit vers quelle adresse, masquée, et quoi faire si ce n'était
 * pas elle : l'ancienne adresse ne peut plus demander de réinitialisation.
 *
 * La nouvelle adresse est saisie par qui fait le changement : elle n'entre dans
 * le courriel que réduite à des caractères qui ne disent rien au Markdown du
 * gabarit. Le nom du compte n'y entre pas du tout, pour la même raison.
 *
 * Mise en file, et seulement après la validation de la transaction : un
 * changement annulé ne doit rien annoncer.
 */
final class AdresseDuCompteChangee extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $nouvelleAdresse)
    {
        $this->afterCommit();
    }

    /**
     * Réduit une adresse à ce qui suffit à la reconnaître : la première lettre
     * de la partie locale et du domaine, et l'extension.
     *
     * Un caractère hors de [a-z0-9] est omis plutôt que recopié : l'adresse
     * vient de la saisie, et un `*`, un `[` ou un `_` deviendrait de la mise en
     * forme dans le courriel.
     */
    public static function masquer(string $adresse): string
    {
        $arobase = strrpos($adresse, '@');

        if ($arobase === false) {
            return '•••';
        }

        $domaine = substr($adresse, $arobase + 1);
        $point = strrpos($domaine, '.');
        $extension = $point === false ? '' : substr($domaine, $point + 1);

        return self::premiereLettre(substr($adresse, 0, $arobase)).'•••@'
            .self::premiereLettre($domaine).'•••'
            .(preg_match('/^[a-z0-9-]{1,63}$/i', $extension) === 1 ? '.'.$extension : '');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $_notifiable): MailMessage
    {
        $application = config()->string('app.name');

        return new MailMessage()
            ->subject("L’adresse de ton compte {$application} a changé")
            ->greeting('Bonjour,')
            ->line("L’adresse e-mail de ton compte {$application} vient d’être remplacée par ".self::masquer($this->nouvelleAdresse).'. Ce message part à l’ancienne adresse, celle-ci, qui ne reçoit plus rien du compte et ne peut plus servir à réinitialiser son mot de passe.')
            ->line('Si c’est toi qui as fait ce changement, il n’y a rien à faire.')
            ->line('Si ce n’est pas toi : si tu es encore connecté sur un appareil, remets ton adresse dans ton profil puis change ton mot de passe. Sinon, réponds à ce message pour que nous te rendions l’accès à ton compte.');
    }

    private static function premiereLettre(string $partie): string
    {
        $premiere = mb_substr($partie, 0, 1);

        return preg_match('/^[a-z0-9]$/i', $premiere) === 1 ? $premiere : '';
    }
}
