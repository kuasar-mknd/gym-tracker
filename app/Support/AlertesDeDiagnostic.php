<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EvenementDAlerteDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\JetonDeDiagnostic;
use App\Notifications\AlerteDeDiagnostic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Les alertes de l'accès de diagnostic (#2032), vers `HEALTH_TO_ADDRESS`.
 *
 * `jetonCree()` et `jetonsRevoques()` partent des actions de création et de
 * révocation ; `premierUsage()`, `jetonInvalidePresente()` et
 * `debitDepasse()` sont ce que la garde HTTP appellera, avec la ligne du
 * jeton connu : jamais le texte présenté, qui ne borne aucune clef. Aucune ne
 * lit ni n'écrit en base : un refus n'écrit rien. Les limites vivent dans le
 * cache, et une clef évincée coûte un courriel de plus, jamais un de moins.
 * Une alerte qui échoue est rapportée et ne défait ni ne retarde ce qu'elle
 * annonce.
 */
final class AlertesDeDiagnostic
{
    /** Une alerte de jeton présenté mort par jeton et par quinze minutes… */
    public const int SECONDES_ENTRE_DEUX_PRESENTATIONS = 900;

    /** … jusqu'à ce nombre de présentations dans la journée, puis une par jour. */
    public const int PRESENTATIONS_AVANT_LA_CADENCE_JOURNALIERE = 10;

    public const int SECONDES_PAR_JOUR = 86400;

    public const int SECONDES_ENTRE_DEUX_DEBITS_DEPASSES = 3600;

    /** La trace que lit `AccesDeDiagnosticCheck` : l'instant de la dernière présentation d'un jeton mort. */
    public const string CLE_DE_LA_DERNIERE_PRESENTATION = 'sante:acces-de-diagnostic:presentation-d-un-jeton-mort';

    /**
     * Les adresses des alertes, vide si `HEALTH_TO_ADDRESS` l'est.
     *
     * @return list<string>
     */
    public static function adresses(): array
    {
        $configurees = config('health.notifications.mail.to');
        $adresses = [];

        foreach (is_array($configurees) ? $configurees : [$configurees] as $adresse) {
            if (is_string($adresse) && trim($adresse) !== '') {
                $adresses[] = trim($adresse);
            }
        }

        return $adresses;
    }

    /**
     * Les quatre derniers caractères de l'identifiant, comme la route d'état
     * du diagnostic : de quoi reconnaître un jeton, sans le désigner.
     */
    public static function fin(JetonDeDiagnostic $jeton): string
    {
        return substr($jeton->identifiant, -4);
    }

    public function jetonCree(JetonDeDiagnostic $jeton): void
    {
        $this->envoyer(new AlerteDeDiagnostic(
            EvenementDAlerteDeDiagnostic::Creation,
            [self::fin($jeton)],
            $jeton->portees,
            $jeton->expire_le->toIso8601String(),
        ));
    }

    /**
     * Un seul courriel pour tous les jetons que cet appel a révoqués, aucun
     * quand il n'en a révoqué aucun.
     *
     * @param  list<JetonDeDiagnostic>  $jetons
     */
    public function jetonsRevoques(array $jetons, RaisonDeRevocationDuJeton $raison): void
    {
        if ($jetons === []) {
            return;
        }

        $this->envoyer(new AlerteDeDiagnostic(
            EvenementDAlerteDeDiagnostic::Revocation,
            array_map(self::fin(...), $jetons),
            raison: $raison->value,
        ));
    }

    /**
     * Une fois par jeton, pour toute sa vie.
     */
    public function premierUsage(JetonDeDiagnostic $jeton): void
    {
        if (! Cache::add('diagnostic:alerte:premier-usage:'.$jeton->identifiant, true, $jeton->expire_le->copy()->addDay())) {
            return;
        }

        $this->envoyer(new AlerteDeDiagnostic(
            EvenementDAlerteDeDiagnostic::PremierUsage,
            [self::fin($jeton)],
            $jeton->portees,
            $jeton->expire_le->toIso8601String(),
        ));
    }

    /**
     * Un jeton révoqué ou expiré a été présenté. La trace que lit le contrôle
     * de santé se pose à chaque présentation ; le courriel part une fois par
     * jeton et par quinze minutes, puis, dès la onzième présentation de la
     * journée, une fois par jour.
     */
    public function jetonInvalidePresente(JetonDeDiagnostic $jeton): void
    {
        $maintenant = now();

        rescue(static fn (): bool => Cache::put(self::CLE_DE_LA_DERNIERE_PRESENTATION, $maintenant->toIso8601String(), $maintenant->copy()->addDays(2)), report: true);

        $compteur = 'diagnostic:alerte:presentations:'.$jeton->identifiant;
        Cache::add($compteur, 0, self::SECONDES_PAR_JOUR);
        $presentations = Cache::increment($compteur);

        $parJour = is_int($presentations) && $presentations > self::PRESENTATIONS_AVANT_LA_CADENCE_JOURNALIERE;

        $this->uneFoisParPeriode(
            $parJour ? 'diagnostic:alerte:jeton-mort:jour:' : 'diagnostic:alerte:jeton-mort:quinze-minutes:',
            $jeton,
            $parJour ? self::SECONDES_PAR_JOUR : self::SECONDES_ENTRE_DEUX_PRESENTATIONS,
            new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::JetonInvalidePresente, [self::fin($jeton)]),
        );
    }

    /**
     * Le débit d'un jeton a été dépassé : une alerte par heure et par jeton.
     */
    public function debitDepasse(JetonDeDiagnostic $jeton): void
    {
        $this->uneFoisParPeriode(
            'diagnostic:alerte:debit:',
            $jeton,
            self::SECONDES_ENTRE_DEUX_DEBITS_DEPASSES,
            new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::DebitDepasse, [self::fin($jeton)]),
        );
    }

    private function uneFoisParPeriode(string $prefixe, JetonDeDiagnostic $jeton, int $secondes, AlerteDeDiagnostic $alerte): void
    {
        if (RateLimiter::attempt($prefixe.$jeton->identifiant, 1, static fn (): bool => true, $secondes) === true) {
            $this->envoyer($alerte);
        }
    }

    private function envoyer(AlerteDeDiagnostic $alerte): void
    {
        $adresses = self::adresses();

        if ($adresses === []) {
            return;
        }

        rescue(static fn () => Notification::route('mail', $adresses)->notify($alerte), report: true);
    }
}
