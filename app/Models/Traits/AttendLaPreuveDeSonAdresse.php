<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\User;

/**
 * Un compte ouvert par l'inscription attend la preuve de son adresse
 * (`en_attente_depuis`), et toute preuve l'ouvre.
 *
 * L'inscription crée le compte en attente (`CreateUserAction`) : il ne se
 * connecte pas tant que son adresse n'est pas prouvée (`ConnecterLeCompte`),
 * et s'efface s'il ne l'est jamais (`User::prunable()`). L'adresse se prouve
 * par le lien d'ouverture suivi puis la connexion, par une réinitialisation
 * du mot de passe réussie, par la reprise du compte par un fournisseur qui
 * garantit l'adresse, ou par la vérification d'un administrateur : chacune
 * pose `email_verified_at`, et l'écouteur efface alors l'attente. Une
 * écriture qui change aussi l'adresse ne prouve rien (`SurveilleSonAdresse`
 * remet la vérification à null) : l'attente reste.
 *
 * Un compte créé autrement (panneau, fournisseur, fabriques, comptes
 * d'avant la colonne) n'est jamais en attente. Le trait pose son écouteur
 * lui-même (`bootAttendLaPreuveDeSonAdresse()`), comme
 * `DetacheSesAppareilsPush`. Les chemins qui sautent les évènements
 * (`saveQuietly()`, `withoutEvents()`, le constructeur de requêtes) y
 * échappent. Réservé à `User`.
 */
trait AttendLaPreuveDeSonAdresse
{
    /**
     * Pose l'écouteur : une mise à jour qui prouve l'adresse sans la changer
     * ouvre le compte.
     */
    public static function bootAttendLaPreuveDeSonAdresse(): void
    {
        static::updating(static function (User $compte): void {
            if (
                $compte->isDirty('email_verified_at')
                && $compte->email_verified_at !== null
                && ! $compte->isDirty('email')
            ) {
                $compte->en_attente_depuis = null;
            }
        });
    }

    /**
     * Le compte attend-il encore la preuve de son adresse ?
     */
    public function estEnAttente(): bool
    {
        return $this->getRawOriginal('en_attente_depuis') !== null;
    }
}
