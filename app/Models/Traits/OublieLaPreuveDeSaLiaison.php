<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\User;

/**
 * La preuve de la liaison d'un compte à son fournisseur de connexion
 * (`liaison_prouvee_le`) ne survit ni à un changement d'adresse, ni à une
 * liaison réécrite sans elle.
 *
 * Une liaison prouvée s'ouvre par l'identité du fournisseur seule, quelle que
 * soit l'adresse qu'il rend (`ResolveSocialUserAction`) : la preuve dit que le
 * titulaire de l'identité détenait l'adresse du compte. Quand cette adresse
 * change, par le profil ou par le panneau, le compte peut passer à quelqu'un
 * d'autre : le titulaire de la nouvelle adresse le reprend par la
 * réinitialisation du mot de passe, et l'identité liée n'a rien prouvé de
 * cette adresse-là. La liaison redevient donc une liaison sans preuve, qui ne
 * s'ouvre que pour l'adresse exacte du compte, et se prouve de nouveau quand
 * l'identité revient avec elle, garantie par le fournisseur. Sans exception :
 * même une écriture qui pose la preuve en changeant l'adresse la perd.
 *
 * De même, une écriture qui change `provider` ou `provider_id` sans poser la
 * preuve en même temps l'efface : une preuve vaut pour l'identité sur
 * laquelle elle a été faite, pas pour celle qui la remplace.
 *
 * Le trait pose son écouteur lui-même (`bootOublieLaPreuveDeSaLiaison()`),
 * comme `DetacheSesAppareilsPush`, et non depuis `User::booted()`. Les chemins
 * qui sautent les évènements (`saveQuietly()`, `withoutEvents()`, le
 * constructeur de requêtes) y échappent : l'application n'en écrit aucun sur
 * l'adresse ni sur la liaison. Réservé à `User`.
 */
trait OublieLaPreuveDeSaLiaison
{
    /**
     * Pose l'écouteur : avant chaque mise à jour du compte qui change son
     * adresse, ou sa liaison sans la prouver, la preuve s'efface.
     */
    public static function bootOublieLaPreuveDeSaLiaison(): void
    {
        static::updating(static function (User $compte): void {
            $liaisonReecriteSansPreuve = $compte->isDirty(['provider', 'provider_id'])
                && ! $compte->isDirty('liaison_prouvee_le');

            if ($compte->isDirty('email') || $liaisonReecriteSansPreuve) {
                $compte->liaison_prouvee_le = null;
            }
        });
    }
}
