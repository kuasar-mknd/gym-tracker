<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MotifDeLaTentative;
use App\Models\User;
use App\Support\LiensDesCourriels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient le titulaire d'une adresse qui a déjà un compte que quelqu'un a
 * voulu s'en servir : pour s'inscrire, ou pour en faire l'adresse d'un autre
 * compte depuis le profil.
 *
 * L'inscription et le changement d'adresse répondent la même chose qu'une
 * adresse soit libre ou prise : celui qui les demande n'apprend rien. C'est
 * ce courriel, arrivé dans la boîte de l'adresse, qui dit au titulaire quoi
 * faire si c'était lui (se connecter, ou choisir un mot de passe par « Mot de
 * passe oublié ? », qui ouvre aussi un compte en attente) et qu'il n'a rien à
 * faire sinon. Il ne recopie rien de la requête (ni adresse IP, ni nom saisi,
 * ni autre adresse) et part au plus une fois par heure et par compte
 * (`PrevenirLeTitulaireDeLAdresse`). Le lien se bâtit sur `APP_URL`
 * (`LiensDesCourriels`) ; l'avis part en file, une fois la transaction
 * validée.
 */
final class TentativeSurTonAdresse extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MotifDeLaTentative $motif)
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
        $courriel = new MailMessage()->greeting('Bonjour,');

        if ($this->motif === MotifDeLaTentative::Inscription) {
            $courriel->subject("Une inscription à {$application} a été demandée avec ton adresse");
            $courriel->line("Quelqu’un vient de demander à créer un compte {$application} avec cette adresse. Elle a déjà un compte : aucun autre n’a été créé, et le tien n’a pas changé.");
        } else {
            $courriel->subject("Un compte {$application} a demandé à prendre ton adresse");
            $courriel->line("Quelqu’un vient de demander que cette adresse devienne celle de son compte {$application}. Elle est déjà celle de ton compte : rien n’a changé, ni pour le tien ni pour le sien.");
        }

        if ($notifiable->estEnAttente()) {
            return $courriel
                ->line('Ton compte n’est pas encore ouvert : il attend que le lien envoyé à cette adresse à l’inscription soit suivi. Si c’est toi qui l’as demandé, suis ce lien puis connecte-toi. Si tu l’as perdu, ou si tu ne connais pas ce mot de passe, « Mot de passe oublié ? » sur la page de connexion t’en fait choisir un, et ouvre le compte.')
                ->action('Aller à la connexion', LiensDesCourriels::lienDeConnexion())
                ->line('Si ce n’est pas toi, tu n’as rien à faire : un compte jamais ouvert s’efface au bout de '.User::JOURS_AVANT_LA_PURGE.' jours.');
        }

        return $courriel
            ->line('Si c’est toi, tu n’as pas besoin d’un autre compte : connecte-toi avec cette adresse. Mot de passe oublié ? La page de connexion te permet d’en choisir un nouveau.')
            ->action('Aller à la connexion', LiensDesCourriels::lienDeConnexion())
            ->line('Si ce n’est pas toi, tu n’as rien à faire.');
    }
}
