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
 *   une connexion sociale. Sans exception : même une écriture qui pose
 *   `email_verified_at` en changeant l'adresse laisse le compte non vérifié.
 *   Une adresse se prouve après coup, par `markEmailAsVerified()`, dans une
 *   écriture à part ;
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
 *
 * Les écouteurs tournent à chaque enregistrement d'un compte, y compris d'une
 * instance chargée par une sélection partielle (`VerifyDataCoherence` recale
 * ainsi les séries) : ils ne lisent par l'attribut que ce que l'écriture vient
 * de poser, et le reste par `getRawOriginal()`, qui rend null pour une colonne
 * non chargée là où l'attribut lèverait `MissingAttributeException` hors
 * production. Une adresse se change donc sur un compte chargé en entier : une
 * instance sans `email_verified_at` ni `ancienne_adresse_verifiee` tiendrait
 * le compte pour non vérifié et sans mémoire, et ne préviendrait personne.
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
            if ($utilisateur->isDirty('email')) {
                self::retenirLAdresseVerifieeQuittee($utilisateur);
                $utilisateur->email_verified_at = null;

                return;
            }

            if ($utilisateur->isDirty('email_verified_at') && $utilisateur->email_verified_at !== null) {
                $utilisateur->ancienne_adresse_verifiee = null;
            }
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
     * Retient, avant l'écriture, l'adresse vérifiée que le compte quitte.
     *
     * Un compte qui quitte une adresse vérifiée la retient dans
     * `ancienne_adresse_verifiee`. Un compte non vérifié qui change encore
     * d'adresse garde ce qu'il avait retenu. La mémoire se vide quand le compte
     * est de nouveau vérifié (`markEmailAsVerified()`, la connexion sociale qui
     * revérifie le compte) : son adresse fait alors référence.
     */
    private static function retenirLAdresseVerifieeQuittee(User $utilisateur): void
    {
        $ancienneAdresse = $utilisateur->getRawOriginal('email');

        if (
            $utilisateur->getRawOriginal('email_verified_at') !== null
            && is_string($ancienneAdresse)
            && $ancienneAdresse !== ''
        ) {
            $utilisateur->ancienne_adresse_verifiee = $ancienneAdresse;
        }
    }
}
