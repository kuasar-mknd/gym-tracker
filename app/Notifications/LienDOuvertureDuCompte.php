<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\LiensDesCourriels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le lien qui ouvre un compte créé par l'inscription, envoyé à son adresse.
 *
 * Le compte naît en attente (`AttendLaPreuveDeSonAdresse`) : il ne se connecte
 * qu'une fois ce lien suivi, puis le bon mot de passe donné, dans le même
 * navigateur (`ConnecterLeCompte`). Le lien seul n'ouvre rien : suivi par un
 * logiciel de messagerie qui inspecte les liens, ou par le titulaire d'une
 * adresse qu'un autre a inscrite, il n'ouvrirait sinon le compte qu'à qui
 * connaît le mot de passe, celui qui l'a choisi. Il se bâtit sur `APP_URL`
 * (`LiensDesCourriels`), et part en file, une fois la transaction validée.
 *
 * Le courriel ne recopie aucune saisie : ni le nom du compte, ni rien de la
 * requête qui l'a créé.
 */
final class LienDOuvertureDuCompte extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $application = config()->string('app.name');

        return new MailMessage()
            ->subject("Ouvre ton compte {$application}")
            ->greeting('Bonjour,')
            ->line("Un compte {$application} vient d’être demandé avec cette adresse. Il n’est pas encore ouvert : suis ce lien, puis connecte-toi avec cette adresse et ton mot de passe, sur le même appareil.")
            ->action('Ouvrir mon compte', LiensDesCourriels::lienDOuvertureDuCompte($notifiable))
            ->line('Le lien vaut '.LiensDesCourriels::minutesDeValiditeDUnLien().' minutes. Passé ce délai, connecte-toi : un nouveau lien partira.')
            ->line('Sans ce lien, le compte ne s’ouvre pas, et il s’efface au bout de '.User::JOURS_AVANT_LA_PURGE.' jours.')
            ->line('Si tu n’as rien demandé, tu n’as rien à faire.');
    }
}
