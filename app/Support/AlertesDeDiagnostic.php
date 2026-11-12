<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EvenementDAlerteDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\JetonDeDiagnostic;
use App\Notifications\AlerteDeDiagnostic;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Les alertes de l'accès de diagnostic (#2032), vers `HEALTH_TO_ADDRESS`.
 *
 * `jetonCree()` et `jetonsRevoques()` partent des actions de création et de
 * révocation ; `premierUsage()`, `jetonInvalidePresente()` et
 * `debitDepasse()` sont ce que la garde HTTP appellera, avec la ligne du
 * jeton connu : jamais le texte présenté, qui ne borne aucune clef. Aucune ne
 * lit ni n'écrit en base : un refus n'écrit rien.
 *
 * Aucune ne lève : une panne du cache, de la file ou du courrier est
 * rapportée, et ce que l'alerte annonce (une création, une révocation, une
 * requête valide) n'en est ni défait ni retardé. L'envoi n'attend aucune
 * validation de transaction : le courrier ne dépend d'aucune ligne en base.
 *
 * Les limites vivent dans le cache, et une clef évincée coûte un courriel de
 * plus, jamais un de moins. Un créneau est pris dès que le courriel est mis en
 * file : sans adresse valide ou si la mise en file échoue, il est rendu et le
 * prochain appel réessaie ; une fois en file, les essais de la notification
 * (`#[Tries]`, `#[Backoff]`) portent les pannes passagères du courrier.
 *
 * - Premier usage : un courriel par jeton, pour toute sa vie.
 * - Jeton révoqué ou expiré présenté : un courriel par jeton et par quinze
 *   minutes jusqu'à la dixième présentation ; dès la onzième (compteur du
 *   jeton, gardé quatre-vingt-dix jours), un courriel par jour civil (fuseau
 *   de l'application) et toujours pas plus d'un par quinze minutes.
 * - Débit dépassé : un courriel par jeton et par heure.
 */
final class AlertesDeDiagnostic
{
    /** Le plus de présentations d'un jeton mort qui gardent la cadence de quinze minutes. */
    public const int PRESENTATIONS_AVANT_LA_CADENCE_JOURNALIERE = 10;

    /** La trace que lit `AccesDeDiagnosticCheck` : l'instant de la dernière présentation d'un jeton mort. */
    public const string CLE_DE_LA_DERNIERE_PRESENTATION = 'sante:acces-de-diagnostic:presentation-d-un-jeton-mort';

    private const int SECONDES_ENTRE_DEUX_PRESENTATIONS = 900;

    private const int SECONDES_ENTRE_DEUX_DEBITS_DEPASSES = 3600;

    private const int SECONDES_DU_CRENEAU_JOURNALIER = 172800;

    private const int SECONDES_DU_COMPTEUR = JetonDeDiagnostic::JOURS_DE_CONSERVATION * 86400;

    /**
     * Les adresses valides des alertes, vide si `HEALTH_TO_ADDRESS` l'est ou
     * ne porte aucune adresse seule et bien formée.
     *
     * @return list<string>
     */
    public static function adresses(): array
    {
        $configurees = config('health.notifications.mail.to');
        $adresses = [];

        foreach (is_array($configurees) ? $configurees : [$configurees] as $adresse) {
            if (! is_string($adresse)) {
                continue;
            }

            $adresse = trim($adresse);

            if ($adresse !== '' && filter_var($adresse, FILTER_VALIDATE_EMAIL) !== false) {
                $adresses[] = $adresse;
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

    public function premierUsage(JetonDeDiagnostic $jeton): void
    {
        $this->sansLever(fn () => $this->envoyerSiLesCreneauxSontLibres(
            [['diagnostic:alerte:premier-usage:'.$jeton->identifiant, $jeton->expire_le->copy()->addDay()]],
            new AlerteDeDiagnostic(
                EvenementDAlerteDeDiagnostic::PremierUsage,
                [self::fin($jeton)],
                $jeton->portees,
                $jeton->expire_le->toIso8601String(),
            ),
        ));
    }

    /**
     * La trace que lit le contrôle de santé se pose à chaque présentation,
     * même quand le courriel ne part pas. L'instant est celui de la garde,
     * qui a jugé l'expiration d'après lui : une seconde lecture de l'horloge
     * pouvait tomber, près de minuit, sur le jour suivant.
     */
    public function jetonInvalidePresente(JetonDeDiagnostic $jeton, CarbonInterface $maintenant): void
    {
        $marque = 'diagnostic:alerte:presentation-vue:'.$jeton->identifiant.':'.$maintenant->format('Y-m-d\TH:i');
        $surSql = JournalDuDiagnostic::lEcritureSeraitEnSql();

        if ($surSql && rescue(static fn (): bool => Cache::has($marque), false, true)) {
            return;
        }

        rescue(static fn (): bool => Cache::put(self::CLE_DE_LA_DERNIERE_PRESENTATION, $maintenant->toIso8601String(), $maintenant->copy()->addDays(2)), false, true);

        $this->sansLever(function () use ($jeton, $maintenant): void {
            $compteur = 'diagnostic:alerte:presentations:'.$jeton->identifiant;
            Cache::add($compteur, 0, self::SECONDES_DU_COMPTEUR);
            $presentations = Cache::increment($compteur);

            $quinzeMinutes = ['diagnostic:alerte:jeton-mort:quinze-minutes:'.$jeton->identifiant, self::SECONDES_ENTRE_DEUX_PRESENTATIONS];
            $parJour = is_int($presentations) && $presentations > self::PRESENTATIONS_AVANT_LA_CADENCE_JOURNALIERE;

            $this->envoyerSiLesCreneauxSontLibres(
                $parJour
                    ? [['diagnostic:alerte:jeton-mort:jour:'.$jeton->identifiant.':'.$maintenant->format('Y-m-d'), self::SECONDES_DU_CRENEAU_JOURNALIER], $quinzeMinutes]
                    : [$quinzeMinutes],
                new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::JetonInvalidePresente, [self::fin($jeton)]),
            );
        });

        if ($surSql) {
            rescue(static fn (): bool => Cache::put($marque, true, 120), false, true);
        }
    }

    public function debitDepasse(JetonDeDiagnostic $jeton): void
    {
        $this->sansLever(fn () => $this->envoyerSiLesCreneauxSontLibres(
            [['diagnostic:alerte:debit:'.$jeton->identifiant, self::SECONDES_ENTRE_DEUX_DEBITS_DEPASSES]],
            new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::DebitDepasse, [self::fin($jeton)]),
        ));
    }

    /**
     * Prend tous les créneaux, ou aucun, puis envoie ; un envoi qui ne part
     * pas rend ceux qu'il avait pris.
     *
     * @param  list<array{0: string, 1: DateTimeInterface|int}>  $creneaux
     */
    private function envoyerSiLesCreneauxSontLibres(array $creneaux, AlerteDeDiagnostic $alerte): void
    {
        $pris = [];

        foreach ($creneaux as [$cle, $duree]) {
            if (! Cache::add($cle, true, $duree)) {
                $this->rendre($pris);

                return;
            }

            $pris[] = $cle;
        }

        if (! $this->envoyer($alerte)) {
            $this->rendre($pris);
        }
    }

    /**
     * @param  list<string>  $cles
     */
    private function rendre(array $cles): void
    {
        foreach ($cles as $cle) {
            Cache::forget($cle);
        }
    }

    /**
     * Vrai si le courriel est parti en file.
     */
    private function envoyer(AlerteDeDiagnostic $alerte): bool
    {
        $adresses = self::adresses();

        if ($adresses === []) {
            return false;
        }

        return rescue(static function () use ($adresses, $alerte): bool {
            Notification::route('mail', $adresses)->notify($alerte);

            return true;
        }, false, true);
    }

    private function sansLever(Closure $travail): void
    {
        rescue($travail, null, true);
    }
}
