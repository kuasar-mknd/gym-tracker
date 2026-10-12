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
 * suivi (`ConfirmerLAdresseController`).
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

            $titulaire = User::query()
                ->where('email', $this->adresse)
                ->whereKeyNot($compte->getKey())
                ->first();

            if ($titulaire !== null) {
                $prevenirLeTitulaire->execute($titulaire, MotifDeLaTentative::ChangementDAdresse);

                return;
            }

            $this->envoyerLeLien($compte);
        });
    }

    private function envoyerLeLien(User $compte): void
    {
        RateLimiter::attempt(
            'lien-de-changement-d-adresse:'.hash('sha256', strtolower($this->adresse)),
            1,
            function () use ($compte): void {
                Notification::route('mail', $this->adresse)->notify(
                    new LienDeChangementDAdresse(LiensDesCourriels::lienDeConfirmationDAdresse($compte, $this->adresse)),
                );
            },
            self::SECONDES_ENTRE_DEUX_LIENS,
        );
    }
}
