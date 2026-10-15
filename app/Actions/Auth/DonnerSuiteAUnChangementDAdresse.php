<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\MotifDeLaTentative;
use App\Models\User;
use App\Notifications\LienDeChangementDAdresse;
use App\Support\LiensDesCourriels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Décide du courriel que fait partir une demande de changement d'adresse
 * (`TraiterUnChangementDAdresse`) : l'avis au titulaire de l'adresse demandée,
 * ou le lien qui la confirme, envoyé à cette adresse.
 *
 * Le compte qui tient l'adresse se cherche comme l'index unique le juge,
 * selon la collation. Quand un autre compte l'occupe, son titulaire est
 * prévenu (`PrevenirLeTitulaireDeLAdresse`). Sinon, le lien part à la
 * nouvelle adresse (`LienDeChangementDAdresse`), au plus un par quart d'heure
 * et par adresse : l'adresse du compte ne change qu'une fois ce lien suivi
 * (`ConfirmerLAdresseController`). Un compte que l'inscription a ouvert sur
 * exactement cette adresse, jamais ouvert depuis, ne compte pas : rien ne
 * prouve que son inscrit détienne la boîte, n'importe qui tape l'adresse d'un
 * autre. Le lien part donc aussi, et le suivre efface ce compte : le
 * titulaire de la boîte ne perd pas son adresse au profit d'un tiers.
 */
final readonly class DonnerSuiteAUnChangementDAdresse
{
    /**
     * Le temps, en secondes, entre deux liens de confirmation vers la même adresse.
     */
    public const int SECONDES_ENTRE_DEUX_LIENS = 900;

    public function __construct(private PrevenirLeTitulaireDeLAdresse $prevenirLeTitulaire)
    {
    }

    /**
     * Prévient le titulaire de l'adresse, ou envoie le lien qui la confirme ;
     * rend vrai quand un courriel part.
     */
    public function execute(User $compte, string $adresse): bool
    {
        $titulaire = User::query()
            ->where('email', $adresse)
            ->whereKeyNot($compte->getKey())
            ->first();

        if ($titulaire !== null && ! self::nEstQuUneInscriptionJamaisOuverte($titulaire, $adresse)) {
            return $this->prevenirLeTitulaire->execute($titulaire, MotifDeLaTentative::ChangementDAdresse);
        }

        return self::envoyerLeLien($compte, $adresse);
    }

    /**
     * Ce compte n'occupe-t-il l'adresse que par une inscription jamais
     * ouverte, sur cette adresse octet pour octet ? La collation, elle, ne
     * décide pas d'une identité (`.ai/rules/actions.md`).
     */
    private static function nEstQuUneInscriptionJamaisOuverte(User $titulaire, string $adresse): bool
    {
        return $titulaire->estEnAttente() && $titulaire->email === $adresse;
    }

    /**
     * Envoie le lien, au plus un par quart d'heure et par adresse ; rend vrai
     * quand il part.
     */
    private static function envoyerLeLien(User $compte, string $adresse): bool
    {
        return RateLimiter::attempt(
            'lien-de-changement-d-adresse:'.hash('sha256', strtolower($adresse)),
            1,
            static function () use ($compte, $adresse): void {
                Notification::route('mail', $adresse)->notify(
                    new LienDeChangementDAdresse(LiensDesCourriels::lienDeConfirmationDAdresse($compte, $adresse)),
                );
            },
            self::SECONDES_ENTRE_DEUX_LIENS,
        ) !== false;
    }
}
