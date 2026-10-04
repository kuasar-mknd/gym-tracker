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
 * - l'ancienne adresse reçoit `AdresseDuCompteChangee`, une fois la
 *   transaction validée.
 *
 * Le mot de passe actuel n'est pas exigé ici : c'est l'affaire de la requête
 * du profil (`ProfileUpdateRequest`). Un administrateur du panneau change une
 * adresse sans le mot de passe du compte, et l'ancienne adresse en est
 * prévenue comme pour un changement fait depuis le profil.
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
        });

        static::updated(function (User $utilisateur): void {
            $ancienneAdresse = $utilisateur->getRawOriginal('email');

            if (! $utilisateur->wasChanged('email') || ! is_string($ancienneAdresse) || $ancienneAdresse === '') {
                return;
            }

            Notification::route('mail', $ancienneAdresse)
                ->notify(new AdresseDuCompteChangee($utilisateur->email));
        });
    }
}
