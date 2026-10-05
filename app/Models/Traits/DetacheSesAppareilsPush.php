<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\User;

/**
 * Un mot de passe changé détache du compte tous ses appareils abonnés aux
 * notifications push.
 *
 * Depuis #1940, changer le mot de passe ferme les autres sessions du compte
 * (`AuthentifieLaSessionDuCompte`). Leurs appareils gardaient pourtant leur
 * abonnement, et continuaient d'afficher records, rappels et succès sur
 * l'écran verrouillé : précisément l'appareil volé, partagé ou perdu dont la
 * personne voulait se défaire. Le serveur ne sait pas quel abonnement
 * appartient à quelle session, la table ne porte ni l'une ni l'autre : tous
 * partent. La page du profil retransmet ensuite celui de l'appareil qui a
 * changé le mot de passe. Un appareil retiré qui se reconnecte au compte
 * retransmet le sien à son tour (`useAbonnementPush`).
 *
 * Une session fermée ne peut plus en transmettre : le middleware la refuse à
 * l'entrée de la requête, et `PushSubscriptionController` la revérifie avant
 * d'écrire, sous le verrou de la ligne du compte, que la mise à jour du mot de
 * passe prend aussi. Sans cette revérification, une transmission partie avant
 * le changement s'écrivait après ce retrait, et la session fermée gardait son
 * abonnement.
 *
 * Dans l'évènement du modèle plutôt que dans chaque contrôleur, pour la même
 * raison que le middleware ferme les sessions : le profil, la réinitialisation
 * par courriel, le panneau et tout chemin à venir changent le mot de passe par
 * un enregistrement du compte, et une règle que chacun devrait rappeler finit
 * oubliée par l'un d'eux. Seuls les chemins qui sautent les évènements y
 * échappent (`saveQuietly()`, `withoutEvents()`, le constructeur de
 * requêtes) ; l'application n'en écrit aucun sur le mot de passe.
 *
 * Le re-hachage à la connexion, quand le coût du hachage change
 * (`hashing.rehash_on_login`), compte aussi comme un changement : il ferme
 * déjà les autres sessions du compte, et chaque appareil retransmet son
 * abonnement à sa connexion suivante.
 *
 * Le trait pose son écouteur lui-même, par `bootDetacheSesAppareilsPush()`,
 * qu'Eloquent appelle au démarrage du modèle pour chaque trait qui en porte
 * une, et non depuis `User::booted()` : une classe ne déclare `booted()`
 * qu'une fois, et deux protections qui l'occuperaient chacune n'y tiendraient
 * qu'en fusionnant leurs corps à la main, au risque d'en perdre une. Le
 * retrait des appareils ne dépend ainsi d'aucun autre écouteur du compte.
 * Réservé à `User`.
 */
trait DetacheSesAppareilsPush
{
    /**
     * Pose l'écouteur : à chaque mise à jour du compte qui a changé le mot de
     * passe, ses abonnements push partent.
     */
    public static function bootDetacheSesAppareilsPush(): void
    {
        static::updated(static function (User $compte): void {
            if ($compte->wasChanged('password')) {
                $compte->detacherSesAppareilsPush();
            }
        });
    }

    /**
     * Retire tous les abonnements push du compte, par sa relation, qui filtre
     * sur le type et l'identifiant. La relation déjà chargée est oubliée : le
     * canal WebPush lit les adresses par elle, et une notification envoyée par
     * cette instance dans la même requête partirait sinon vers les appareils
     * retirés.
     */
    private function detacherSesAppareilsPush(): void
    {
        $this->pushSubscriptions()->delete();
        $this->unsetRelation('pushSubscriptions');
    }
}
