<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Auth\DonnerSuiteAUnChangementDAdresse;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Traits\Localizable;

/**
 * Donne suite à une demande de changement d'adresse faite depuis le profil,
 * hors de la requête qui l'a faite.
 *
 * Le profil (`ProfileController::update()`) répond la même chose que la
 * nouvelle adresse soit libre ou prise, et ne cherche pas de compte : c'est
 * ici qu'on le cherche, par `DonnerSuiteAUnChangementDAdresse`, qui prévient
 * le titulaire de l'adresse prise ou envoie à la nouvelle adresse le lien qui
 * la confirme. L'adresse du compte ne change qu'une fois ce lien suivi
 * (`ConfirmerLAdresseController`).
 *
 * Un même compte fait partir au plus `COURRIELS_PAR_HEURE_ET_PAR_COMPTE`
 * courriels par heure, liens et avis confondus, quelles que soient les
 * adresses demandées : sans cette borne, une session ferait écrire
 * l'application à autant d'adresses que le limiteur de la route le permet.
 * Au-delà, la demande ne fait rien, et la réponse du profil ne change pas.
 *
 * Comme `EnvoyerLeLienDeReinitialisation` : chiffré dans la file (l'adresse
 * est une saisie, peut-être celle d'un tiers), mis en file après la
 * validation de la transaction, et traité dans la langue de la requête.
 */
final class TraiterUnChangementDAdresse implements ShouldBeEncrypted, ShouldQueue
{
    use Localizable;
    use Queueable;

    /**
     * Les courriels, liens et avis confondus, que les demandes d'un même
     * compte font partir en une heure.
     */
    public const int COURRIELS_PAR_HEURE_ET_PAR_COMPTE = 3;

    public function __construct(public int $idDuCompte, public string $adresse, public string $langue)
    {
        $this->afterCommit();
    }

    public function handle(DonnerSuiteAUnChangementDAdresse $donnerSuite): void
    {
        $this->withLocale($this->langue, function () use ($donnerSuite): void {
            $compte = User::query()->find($this->idDuCompte);

            if ($compte === null || $compte->email === $this->adresse) {
                return;
            }

            $cle = 'courriels-du-changement-d-adresse:'.$compte->id;

            if (! RateLimiter::tooManyAttempts($cle, self::COURRIELS_PAR_HEURE_ET_PAR_COMPTE) && $donnerSuite->execute($compte, $this->adresse)) {
                RateLimiter::hit($cle, 3600);
            }
        });
    }
}
