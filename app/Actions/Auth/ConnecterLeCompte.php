<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Connecte le compte que désignent l'adresse et le mot de passe, sauf un
 * compte en attente dont le lien d'ouverture n'a pas été suivi.
 *
 * Un compte créé par l'inscription attend la preuve de son adresse
 * (`AttendLaPreuveDeSonAdresse`). Le lien envoyé à l'adresse ne fait que
 * poser une marque dans la session du navigateur qui le suit
 * (`OuvrirLeCompteController`) : la connexion qui suit, avec le bon mot de
 * passe et cette marque, ouvre le compte (`markEmailAsVerified()`), puis la
 * session. Il faut les deux : le lien prouve l'adresse, le mot de passe que
 * c'est bien l'inscrit. Un lien suivi par le titulaire d'une adresse qu'un
 * autre a inscrite n'ouvre donc rien à ce dernier, comme la vérification de
 * Laravel, qui se fait depuis une session.
 *
 * Sans la marque, le bon mot de passe ne connecte pas : la réponse le dit
 * (`auth.compte_en_attente`), et un nouveau lien part, au plus un par quart
 * d'heure et par compte. Il faut connaître le mot de passe pour lire cette
 * réponse. Un mauvais mot de passe sur un compte en attente répond, lui,
 * comme une adresse inconnue (`auth.failed`), dans le même temps : la garde
 * vérifie l'empreinte dans sa Timebox, puis `AuthenticatedSessionController`
 * pose le plancher commun. Un compte qui n'est pas en attente, dont les
 * comptes d'avant la colonne, vérifiés ou non, se connecte comme avant.
 */
final readonly class ConnecterLeCompte
{
    /**
     * La clef de session où le lien d'ouverture suivi laisse sa marque.
     */
    public const string MARQUE_DU_LIEN_SUIVI = 'ouverture_du_compte';

    /**
     * Le temps, en secondes, entre deux liens d'ouverture renvoyés au même compte.
     */
    public const int SECONDES_ENTRE_DEUX_LIENS = 900;

    /**
     * Rend null quand le compte est connecté, et sinon le message du refus.
     *
     * @param  array<string, string>  $identifiants
     */
    public function execute(array $identifiants, bool $seSouvenir, Session $session): ?string
    {
        $compteRetenu = null;

        $connecte = Auth::attemptWhen(
            $identifiants,
            function (Authenticatable $compte) use ($session, &$compteRetenu): bool {
                if (! $compte instanceof User || ! $compte->estEnAttente()) {
                    return true;
                }

                if (! $this->lienSuiviPour($compte, $session)) {
                    $compteRetenu = $compte;

                    return false;
                }

                $session->forget(self::MARQUE_DU_LIEN_SUIVI);

                if ($compte->markEmailAsVerified()) {
                    event(new Verified($compte));
                }

                return true;
            },
            $seSouvenir,
        );

        if ($connecte) {
            return null;
        }

        if (! $compteRetenu instanceof User) {
            return trans('auth.failed');
        }

        RateLimiter::attempt(
            'lien-d-ouverture:'.$compteRetenu->id,
            1,
            static function () use ($compteRetenu): void {
                $compteRetenu->sendEmailVerificationNotification();
            },
            self::SECONDES_ENTRE_DEUX_LIENS,
        );

        return trans('auth.compte_en_attente');
    }

    /**
     * La session porte-t-elle la marque, encore valable, du lien d'ouverture
     * de ce compte, pour son adresse actuelle ?
     */
    private function lienSuiviPour(User $compte, Session $session): bool
    {
        $marque = $session->get(self::MARQUE_DU_LIEN_SUIVI);

        if (! is_array($marque) || ! is_string($marque['empreinte'] ?? null) || ! is_int($marque['expire'] ?? null)) {
            return false;
        }

        return ($marque['compte'] ?? null) === $compte->getKey()
            && hash_equals(sha1($compte->getEmailForVerification()), $marque['empreinte'])
            && $marque['expire'] >= Date::now()->getTimestamp();
    }
}
