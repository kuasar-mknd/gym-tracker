<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EvenementDAlerteDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
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
#[Tries(5)]
#[Backoff(60, 300, 900)]
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
            EvenementDAlerteDeDiagnostic::Revocation => count($this->jetons) > 1 ? 'jetons révoqués' : 'jeton révoqué',
            EvenementDAlerteDeDiagnostic::PremierUsage => 'jeton utilisé pour la première fois',
            EvenementDAlerteDeDiagnostic::JetonInvalidePresente => 'jeton révoqué ou expiré présenté',
            EvenementDAlerteDeDiagnostic::DebitDepasse => 'débit dépassé',
        };
    }

    private function phrase(string $jetons): string
    {
        $pluriel = count($this->jetons) > 1;
        $raison = $this->raison === null ? '' : ' (raison : '.$this->libelleDeLaRaison($this->raison).')';

        return match ($this->evenement) {
            EvenementDAlerteDeDiagnostic::Creation => "Le jeton {$jetons} vient d’être créé.",
            EvenementDAlerteDeDiagnostic::Revocation => ($pluriel ? "Les jetons {$jetons} viennent d’être révoqués" : "Le jeton {$jetons} vient d’être révoqué").$raison.'.',
            EvenementDAlerteDeDiagnostic::PremierUsage => "Le jeton {$jetons} vient de servir pour la première fois.",
            EvenementDAlerteDeDiagnostic::JetonInvalidePresente => "Le jeton {$jetons}, révoqué ou expiré, vient d’être présenté. Il n’a rien ouvert.",
            EvenementDAlerteDeDiagnostic::DebitDepasse => "Le jeton {$jetons} a dépassé son débit autorisé.",
        };
    }

    private function libelleDeLaRaison(string $raison): string
    {
        return match (RaisonDeRevocationDuJeton::tryFrom($raison)) {
            RaisonDeRevocationDuJeton::Manuelle => 'révocation manuelle',
            RaisonDeRevocationDuJeton::Interrupteur => 'accès de diagnostic fermé',
            RaisonDeRevocationDuJeton::MotDePasseChange => 'mot de passe changé',
            RaisonDeRevocationDuJeton::DoubleAuthentificationRetiree => 'double authentification retirée',
            RaisonDeRevocationDuJeton::DoubleAuthentificationChangee => 'double authentification changée',
            RaisonDeRevocationDuJeton::AdministrateurSupprime => 'administrateur supprimé',
            null => $raison,
        };
    }
}
