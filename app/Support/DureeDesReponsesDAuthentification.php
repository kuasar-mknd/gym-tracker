<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Timebox;

/**
 * La durée minimale des réponses d'authentification, une seule pour toutes.
 *
 * Une réponse qui dure plus longtemps pour une adresse qui a un compte le dit
 * aussi sûrement qu'un message : la connexion d'une adresse connue vérifiait
 * le mot de passe (bcrypt) au-delà de la Timebox de la garde, et l'inscription
 * d'une adresse libre hachait le mot de passe là où celle d'une adresse prise
 * répondait aussitôt. L'inscription, la connexion, « Mot de passe oublié » et
 * la réinitialisation passent donc par `appeler()`, et la garde de session,
 * le courtier des mots de passe et la connexion du panneau lisent la même
 * valeur (`auth.timebox_duration`). La demande de changement d'adresse du
 * profil n'y passe pas : elle ne lit pas l'adresse demandée, qu'un job
 * cherche hors de la requête.
 * Une réussite qui connecte peut rendre la main plus tôt (`returnEarly()`) :
 * seul le titulaire la voit. Pas au panneau : il déclare la double
 * authentification, et Filament fait alors durer sa Timebox à chaque étape
 * de la connexion, réussie ou non, pour ne pas dire quel compte l'a activée.
 *
 * Le plancher ne sert que là où un client mesure la durée : dans une requête.
 * Le courtier lit la même valeur, et sa Timebox ne rend jamais la main tôt
 * autour de `sendResetLink()` : le job de « mot de passe oublié »
 * (`EnvoyerLeLienDeReinitialisation`) prend donc un courtier créé sans
 * plancher, pour ne pas endormir un processus de la file à chaque lien.
 *
 * Le plancher doit dépasser le travail le plus long qu'il couvre, une
 * vérification ou un hachage bcrypt au coût de `BCRYPT_ROUNDS` : mesurés
 * environ 0,25 s au coût 12 de la production, 0,5 s à 13, 1 s à 14 (le double
 * à chaque unité, plus sous charge). Une seconde laisse une marge de quatre
 * au coût 12 ; au-delà de 13, relever `AUTH_TIMEBOX_DURATION` avec lui.
 *
 * La valeur se lit à chaque appel, sans rien garder d'une requête à l'autre
 * (Octane). Le conteneur fournit la Timebox, qu'un test peut ainsi observer.
 */
final class DureeDesReponsesDAuthentification
{
    /**
     * Le plancher de production, en microsecondes, faute d'`AUTH_TIMEBOX_DURATION`.
     */
    public const int PLANCHER_PAR_DEFAUT_MICROSECONDES = 1_000_000;

    /**
     * Le plancher en vigueur, en microsecondes : celui de `config/auth.php`, ou
     * celui de production si la clef manque.
     */
    public static function microsecondes(): int
    {
        return config()->integer('auth.timebox_duration', self::PLANCHER_PAR_DEFAUT_MICROSECONDES);
    }

    /**
     * Fait le travail en au moins le plancher en vigueur, exception comprise.
     *
     * @template TResultat
     *
     * @param  callable(Timebox): TResultat  $travail
     * @return TResultat
     */
    public static function appeler(callable $travail): mixed
    {
        return app(Timebox::class)->call($travail, self::microsecondes());
    }
}
