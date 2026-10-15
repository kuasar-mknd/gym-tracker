<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\LiensDesCourriels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le lien qui fait de la nouvelle adresse demandée depuis le profil celle du
 * compte, envoyé à cette nouvelle adresse (`DonnerSuiteAUnChangementDAdresse`,
 * depuis `TraiterUnChangementDAdresse`).
 *
 * L'adresse du compte ne change qu'une fois ce lien suivi, depuis une session
 * du compte (`ConfirmerLAdresseController`) : la réponse du profil, et la page
 * qui la suit, sont ainsi les mêmes que l'adresse demandée soit libre ou déjà
 * celle d'un autre compte. Le lien, signé, est bâti sur `APP_URL` dans la file
 * (`LiensDesCourriels::lienDeConfirmationDAdresse()`) ; il ne porte la
 * nouvelle adresse que chiffrée, mais l'avis part vers elle, d'où une file
 * chiffrée. L'avis part une fois la transaction validée, et ne recopie ni le
 * nom du compte ni son adresse actuelle.
 */
final class LienDeChangementDAdresse extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $lien)
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

    public function toMail(object $_notifiable): MailMessage
    {
        $application = config()->string('app.name');

        return new MailMessage()
            ->subject("Confirme ta nouvelle adresse {$application}")
            ->greeting('Bonjour,')
            ->line("Tu as demandé que cette adresse devienne celle de ton compte {$application}. Elle ne le deviendra qu’une fois ce lien suivi, depuis un appareil où tu es connecté.")
            ->action('Confirmer cette adresse', $this->lien)
            ->line('Le lien vaut '.LiensDesCourriels::minutesDeValiditeDUnLien().' minutes. Ton adresse actuelle sera prévenue du changement.')
            ->line('Si tu n’as rien demandé, tu n’as rien à faire : l’adresse du compte ne changera pas.');
    }
}
