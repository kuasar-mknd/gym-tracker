<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\User;
use App\Notifications\AdresseDuCompteChangee;
use Illuminate\Support\Facades\Notification;

/**
 * L'adresse de connexion d'un compte ne change pas en silence.
 *
 * L'adresse est la clef de la réinitialisation du mot de passe : qui la
 * remplace peut ensuite choisir le mot de passe. Deux effets suivent donc tout
 * changement d'adresse d'un compte existant, par le profil comme par le
 * panneau (page de modification et action de la table, qui partagent le
 * formulaire) :
 *
 * - la nouvelle adresse repasse non vérifiée (`User` implémente
 *   `MustVerifyEmail`) : sans quoi une adresse que personne n'a prouvée
 *   resterait marquée vérifiée, et `ResolveSocialUserAction` y rattacherait
 *   une connexion sociale. Un appelant qui pose lui-même `email_verified_at`
 *   dans la même écriture est laissé maître de la valeur ;
 * - la dernière adresse vérifiée du compte reçoit `AdresseDuCompteChangee`,
 *   une fois la transaction validée. C'est l'ancienne adresse si elle était
 *   vérifiée. Sinon, c'est celle que le compte a retenue
 *   (`ancienne_adresse_verifiee`) en quittant sa dernière adresse vérifiée,
 *   jusqu'à ce qu'il soit vérifié de nouveau : un changement laisse le compte
 *   non vérifié, et le changement suivant serait sinon passé sous silence.
 *   Une adresse que personne n'a prouvée n'est jamais prévenue : l'inscription
 *   ne demande aucune preuve de l'adresse, et une telle adresse peut être
 *   celle d'un tiers, qui n'a pas à recevoir les avis d'un compte qui n'est
 *   pas le sien. L'avis ne part pas non plus à l'adresse que le compte vient
 *   de prendre (à la casse ASCII près), celle d'un titulaire qui remet la
 *   sienne.
 *
 * Le mot de passe actuel n'est pas exigé ici : c'est l'affaire de la requête
 * du profil (`ProfileUpdateRequest`). Un administrateur du panneau change une
 * adresse sans le mot de passe du compte, et la dernière adresse vérifiée en
 * est prévenue comme pour un changement fait depuis le profil.
 *
 * Les chemins qui sautent les événements (`saveQuietly()`, `withoutEvents()`,
 * le constructeur de requêtes) y échappent. Réservé à `User`.
 */
trait SurveilleSonAdresse
{
    /**
     * Pose les deux écouteurs. Appelé par `User::booted()`, comme les autres
     * modèles posent les leurs.
     */
    protected static function surveillerLAdresse(): void
    {
        static::updating(function (User $utilisateur): void {
            if ($utilisateur->isDirty('email') && ! $utilisateur->isDirty('email_verified_at')) {
                $utilisateur->email_verified_at = null;
            }

            self::retenirLaDerniereAdresseVerifiee($utilisateur);
        });

        static::updated(function (User $utilisateur): void {
            if (! $utilisateur->wasChanged('email')) {
                return;
            }

            $adresseAPrevenir = $utilisateur->getRawOriginal('email_verified_at') !== null
                ? $utilisateur->getRawOriginal('email')
                : $utilisateur->getRawOriginal('ancienne_adresse_verifiee');

            if (
                ! is_string($adresseAPrevenir)
                || $adresseAPrevenir === ''
                || strcasecmp($adresseAPrevenir, $utilisateur->email) === 0
            ) {
                return;
            }

            Notification::route('mail', $adresseAPrevenir)
                ->notify(new AdresseDuCompteChangee($utilisateur->email));
        });
    }

    /**
     * Tient `ancienne_adresse_verifiee` avant l'écriture.
     *
     * Un compte vérifié n'a rien à retenir : son adresse actuelle fait
     * référence, et la mémoire se vide (`markEmailAsVerified()`, la connexion
     * sociale qui revérifie le compte). Un compte qui quitte une adresse
     * vérifiée retient celle-ci. Un compte non vérifié qui change encore
     * d'adresse garde ce qu'il avait retenu.
     *
     * La colonne se lit par `getRawOriginal()`, jamais par l'attribut : une
     * instance chargée sans elle lèverait `MissingAttributeException` hors
     * production.
     */
    private static function retenirLaDerniereAdresseVerifiee(User $utilisateur): void
    {
        if ($utilisateur->email_verified_at !== null) {
            if ($utilisateur->getRawOriginal('ancienne_adresse_verifiee') !== null) {
                $utilisateur->ancienne_adresse_verifiee = null;
            }

            return;
        }

        $ancienneAdresse = $utilisateur->getRawOriginal('email');

        if (
            $utilisateur->isDirty('email')
            && $utilisateur->getRawOriginal('email_verified_at') !== null
            && is_string($ancienneAdresse)
            && $ancienneAdresse !== ''
        ) {
            $utilisateur->ancienne_adresse_verifiee = $ancienneAdresse;
        }
    }
}
