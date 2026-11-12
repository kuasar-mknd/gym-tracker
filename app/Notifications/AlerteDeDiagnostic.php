<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EvenementDAlerteDeDiagnostic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Prévient l'exploitant (`HEALTH_TO_ADDRESS`) qu'un jeton de diagnostic est
 * né, mort, servi pour la première fois, présenté mort ou trop présenté
 * (#2032).
 *
 * Elle ne porte que des scalaires, jamais le modèle : ni le nom du jeton, que
 * le créateur tape, ni un texte venu de l'extérieur ne partent dans la file,
 * dans `failed_jobs` ou dans le courriel. Les jetons y sont désignés par les
 * quatre derniers caractères de leur identifiant ; les portées et la raison
 * sont des mots fermés ; l'expiration est un instant. Rien n'y dépend d'une
 * ligne en base : elle part sans attendre la validation de la transaction qui
 * l'englobe (`afterCommit` ferait lever l'appelant à la validation, hors de
 * la garde d'`AlertesDeDiagnostic`).
 */
final class AlerteDeDiagnostic extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $jetons  les quatre derniers caractères de chaque identifiant
     * @param  list<string>  $portees
     */
    public function __construct(
        public EvenementDAlerteDeDiagnostic $evenement,
        public array $jetons,
        public array $portees = [],
        public ?string $expireLe = null,
        public ?string $raison = null,
    ) {
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
        $jetons = implode(', ', array_map(static fn (string $fin): string => '…'.$fin, $this->jetons));

        $courriel = new MailMessage();
        $courriel->subject("Accès de diagnostic {$application} : ".$this->objet());
        $courriel->line($this->phrase($jetons));

        if ($this->portees !== []) {
            $courriel->line('Portées : '.implode(', ', $this->portees).'.');
        }

        if ($this->expireLe !== null) {
            $courriel->line('Il expire le '.Carbon::parse($this->expireLe)->timezone(config()->string('app.timezone'))->format('d/m/Y à H:i').'.');
        }

        return $courriel->line('Si ce n’est pas toi, ouvre « Accès de diagnostic » dans le panneau et révoque tout, ou lance `php artisan diagnostic:revoquer --tous` sur le serveur.');
    }

    private function objet(): string
    {
        return match ($this->evenement) {
            EvenementDAlerteDeDiagnostic::Creation => 'jeton créé',
            EvenementDAlerteDeDiagnostic::Revocation => 'jeton révoqué',
            EvenementDAlerteDeDiagnostic::PremierUsage => 'jeton utilisé pour la première fois',
            EvenementDAlerteDeDiagnostic::JetonInvalidePresente => 'jeton révoqué ou expiré présenté',
            EvenementDAlerteDeDiagnostic::DebitDepasse => 'débit dépassé',
        };
    }

    private function phrase(string $jetons): string
    {
        return match ($this->evenement) {
            EvenementDAlerteDeDiagnostic::Creation => "Le jeton {$jetons} vient d’être créé.",
            EvenementDAlerteDeDiagnostic::Revocation => "Le jeton {$jetons} vient d’être révoqué".($this->raison === null ? '' : " (raison : {$this->raison})").'.',
            EvenementDAlerteDeDiagnostic::PremierUsage => "Le jeton {$jetons} vient de servir pour la première fois.",
            EvenementDAlerteDeDiagnostic::JetonInvalidePresente => "Le jeton {$jetons}, révoqué ou expiré, vient d’être présenté. Il n’a rien ouvert.",
            EvenementDAlerteDeDiagnostic::DebitDepasse => "Le jeton {$jetons} a dépassé son débit autorisé.",
        };
    }
}
