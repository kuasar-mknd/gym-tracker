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
 * Sans la marque, le bon mot de passe ne connecte pas, et un nouveau lien
 * part, au plus un par quart d'heure et par compte. Le refus est celui de
 * tout autre (`auth.failed`, écrit pour les deux cas) : n'importe qui
 * s'inscrit avec une adresse et le mot de passe de son choix, puis se
 * connecte avec lui ; un refus propre au compte en attente lui dirait que
 * l'adresse était libre, puisqu'une adresse prise refuse ce mot de passe.
 * Adresse inconnue, mauvais mot de passe et bon mot de passe sans la marque
 * répondent donc de même, dans le même temps : la garde vérifie l'empreinte,
 * puis appelle la fonction qui décide, dans sa Timebox, réglée sur le
 * plancher commun (`auth.timebox_duration`), et le lien repart dans cette
 * fonction, avant le refus. Rien ne couvrirait un travail fait après la
 * garde : la Timebox d'`AuthenticatedSessionController`, de même durée, a
 * démarré avant celle de la garde et s'est écoulée avec elle. Un compte qui
 * n'est pas en attente, dont les comptes d'avant la colonne, vérifiés ou
 * non, se connecte comme avant.
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
     * Le compte est-il connecté ? Un refus, quel qu'il soit, rend faux.
     *
     * @param  array<string, string>  $identifiants
     */
    public function execute(array $identifiants, bool $seSouvenir, Session $session): bool
    {
        return Auth::attemptWhen(
            $identifiants,
            function (Authenticatable $compte) use ($session): bool {
                if (! $compte instanceof User || ! $compte->estEnAttente()) {
                    return true;
                }

                if (! $this->lienSuiviPour($compte, $session)) {
                    $this->renvoyerLeLien($compte);

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
    }

    /**
     * Renvoie le lien d'ouverture au compte en attente dont on vient de donner
     * le bon mot de passe, au plus un par quart d'heure : rien n'en paraît
     * dans la réponse, ni dans sa durée, puisqu'il part dans la Timebox de la
     * garde.
     */
    private function renvoyerLeLien(User $compte): void
    {
        RateLimiter::attempt(
            'lien-d-ouverture:'.$compte->id,
            1,
            static function () use ($compte): void {
                $compte->sendEmailVerificationNotification();
            },
            self::SECONDES_ENTRE_DEUX_LIENS,
        );
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
