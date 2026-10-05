<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient la dernière adresse VÉRIFIÉE d'un compte que l'adresse de connexion
 * a changé : l'ancienne adresse, ou celle que le compte a quittée en dernier
 * quand il n'a pas été vérifié depuis (`SurveilleSonAdresse`).
 *
 * Elle part par une route à la demande (`Notification::route('mail', …)`) : le
 * compte porte déjà la nouvelle adresse, et c'est précisément l'ancienne, celle
 * de la personne qui pourrait ne pas être à l'origine du changement, qu'il faut
 * atteindre. Elle dit vers quelle adresse, masquée, et quoi faire si ce n'était
 * pas elle : l'ancienne adresse ne peut plus demander de réinitialisation.
 *
 * Le masque ne garde que quelques lettres, et l'auteur du changement choisit la
 * nouvelle adresse : il peut en prendre une dont la forme masquée est celle de
 * l'adresse prévenue. L'avis le dit alors en toutes lettres, pour que le
 * titulaire ne croie pas y lire sa propre adresse.
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
     * de la partie locale et du domaine, et l'extension quand elle a la forme
     * d'une extension courante.
     *
     * L'adresse vient de la saisie de qui fait le changement, et le courriel
     * doit alerter quelqu'un d'autre : elle n'y entre ni comme mise en forme,
     * ni comme texte.
     *
     * - Un caractère hors de [a-z0-9] est omis plutôt que recopié : un `*`, un
     *   `[` ou un `_` deviendrait de la mise en forme dans le courriel.
     * - L'extension n'est recopiée que si elle compte de deux à six lettres
     *   (`fr`, `org`, `com`, `uk`, `online`…), et omise sinon. La règle
     *   `email` accepte un dernier label de soixante-trois lettres, chiffres
     *   et tirets : recopié, il porterait dans l'avis une phrase ou un numéro
     *   choisis par l'auteur du changement, à la place d'une extension. Six
     *   lettres sans séparateur couvrent les messageries courantes sans laisser
     *   la place d'une phrase.
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
            .(preg_match('/^[a-z]{2,6}$/i', $extension) === 1 ? '.'.$extension : '');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $application = config()->string('app.name');
        $nouvelleAdresseMasquee = self::masquer($this->nouvelleAdresse);
        $courriel = new MailMessage()
            ->subject("L’adresse de ton compte {$application} a changé")
            ->greeting('Bonjour,')
            ->line("L’adresse e-mail de ton compte {$application} vient d’être remplacée par {$nouvelleAdresseMasquee}. Ce message part à une ancienne adresse du compte, celle-ci, la dernière à avoir été vérifiée : elle ne reçoit plus rien du compte et ne peut plus servir à réinitialiser son mot de passe.");

        if ($nouvelleAdresseMasquee === self::masquer(self::adresseDuDestinataire($notifiable))) {
            $courriel->line('Une fois masquée, la nouvelle adresse ressemble à celle-ci : c’en est pourtant une autre.');
        }

        return $courriel
            ->line('Si c’est toi qui as fait ce changement, il n’y a rien à faire.')
            ->line('Si ce n’est pas toi : si tu es encore connecté sur un appareil, remets ton adresse dans ton profil puis change ton mot de passe. Sinon, réponds à ce message pour que nous te rendions l’accès à ton compte.');
    }

    /**
     * L'adresse à laquelle part l'avis, ou une chaîne vide.
     *
     * L'avis part par une route à la demande ; un autre destinataire n'a pas
     * d'adresse à comparer.
     */
    private static function adresseDuDestinataire(object $notifiable): string
    {
        $adresse = $notifiable instanceof AnonymousNotifiable ? $notifiable->routeNotificationFor('mail') : null;

        return is_string($adresse) ? $adresse : '';
    }

    private static function premiereLettre(string $partie): string
    {
        $premiere = mb_substr($partie, 0, 1);

        return preg_match('/^[a-z0-9]$/i', $premiere) === 1 ? $premiere : '';
    }
}
