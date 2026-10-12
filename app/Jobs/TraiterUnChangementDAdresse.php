<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Auth\PrevenirLeTitulaireDeLAdresse;
use App\Enums\MotifDeLaTentative;
use App\Models\User;
use App\Notifications\LienDeChangementDAdresse;
use App\Support\LiensDesCourriels;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Traits\Localizable;

/**
 * Donne suite à une demande de changement d'adresse faite depuis le profil,
 * hors de la requête qui l'a faite.
 *
 * Le profil (`ProfileController::update()`) répond la même chose que la
 * nouvelle adresse soit libre ou prise, et ne cherche pas de compte : c'est
 * ici qu'on le cherche, comme l'index unique le juge, selon la collation.
 * Quand un autre compte l'occupe, son titulaire est prévenu
 * (`PrevenirLeTitulaireDeLAdresse`). Sinon, le lien qui la confirme part à
 * la nouvelle adresse (`LienDeChangementDAdresse`), au plus un par quart
 * d'heure et par adresse : l'adresse du compte ne change qu'une fois ce lien
 * suivi (`ConfirmerLAdresseController`). Un compte que l'inscription a ouvert
 * sur exactement cette adresse, jamais ouvert depuis, ne compte pas : rien ne
 * prouve que son inscrit détienne la boîte, n'importe qui tape l'adresse d'un
 * autre. Le lien part donc aussi, et le suivre efface ce compte : le
 * titulaire de la boîte ne perd pas son adresse au profit d'un tiers.
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
     * Le temps, en secondes, entre deux liens de confirmation vers la même adresse.
     */
    public const int SECONDES_ENTRE_DEUX_LIENS = 900;

    /**
     * Les courriels, liens et avis confondus, que les demandes d'un même
     * compte font partir en une heure.
     */
    public const int COURRIELS_PAR_HEURE_ET_PAR_COMPTE = 3;

    public function __construct(public int $idDuCompte, public string $adresse, public string $langue)
    {
        $this->afterCommit();
    }

    public function handle(PrevenirLeTitulaireDeLAdresse $prevenirLeTitulaire): void
    {
        $this->withLocale($this->langue, function () use ($prevenirLeTitulaire): void {
            $compte = User::query()->find($this->idDuCompte);

            if ($compte === null || $compte->email === $this->adresse) {
                return;
            }

            $cle = 'courriels-du-changement-d-adresse:'.$compte->id;

            if (! RateLimiter::tooManyAttempts($cle, self::COURRIELS_PAR_HEURE_ET_PAR_COMPTE) && $this->donnerSuite($compte, $prevenirLeTitulaire)) {
                RateLimiter::hit($cle, 3600);
            }
        });
    }

    /**
     * Prévient le titulaire de l'adresse, ou envoie le lien qui la confirme ;
     * rend vrai quand un courriel part.
     */
    private function donnerSuite(User $compte, PrevenirLeTitulaireDeLAdresse $prevenirLeTitulaire): bool
    {
        $titulaire = User::query()
            ->where('email', $this->adresse)
            ->whereKeyNot($compte->getKey())
            ->first();

        if ($titulaire !== null && ! $this->nEstQuUneInscriptionJamaisOuverte($titulaire)) {
            return $prevenirLeTitulaire->execute($titulaire, MotifDeLaTentative::ChangementDAdresse);
        }

        return $this->envoyerLeLien($compte);
    }

    /**
     * Ce compte n'occupe-t-il l'adresse que par une inscription jamais
     * ouverte, sur cette adresse octet pour octet ? La collation, elle, ne
     * décide pas d'une identité (`.ai/rules/actions.md`).
     */
    private function nEstQuUneInscriptionJamaisOuverte(User $titulaire): bool
    {
        return $titulaire->estEnAttente() && $titulaire->email === $this->adresse;
    }

    /**
     * Envoie le lien, au plus un par quart d'heure et par adresse ; rend vrai
     * quand il part.
     */
    private function envoyerLeLien(User $compte): bool
    {
        return RateLimiter::attempt(
            'lien-de-changement-d-adresse:'.hash('sha256', strtolower($this->adresse)),
            1,
            function () use ($compte): void {
                Notification::route('mail', $this->adresse)->notify(
                    new LienDeChangementDAdresse(LiensDesCourriels::lienDeConfirmationDAdresse($compte, $this->adresse)),
                );
            },
            self::SECONDES_ENTRE_DEUX_LIENS,
        ) !== false;
    }
}
