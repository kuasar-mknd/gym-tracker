<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Traits\Localizable;

/**
 * Envoie le lien de réinitialisation du mot de passe, hors de la requête qui
 * l'a demandé (#1951).
 *
 * La demande (`SendPasswordResetLinkAction`) ne fait que mettre ce job en
 * file : sa réponse, et le temps qu'elle prend, sont les mêmes que l'adresse
 * ait un compte ou non. C'est ici que le courtier cherche le compte, refuse une
 * demande trop rapprochée (`auth.passwords.users.throttle`), crée le jeton et
 * envoie l'avis : ce travail, que seule une adresse connue coûte, ne pèse plus
 * sur la réponse. Son statut ne sort pas d'ici : une adresse inconnue, ou une
 * demande limitée, n'envoie simplement rien.
 *
 * Le courriel part dans la langue de la requête, figée à l'envoi du job ; son
 * lien se bâtit sur `APP_URL` (`LiensDesCourriels`), que le worker connaît
 * comme le serveur web. Le job attend la validation de la transaction en cours,
 * s'il y en a une, et il est chiffré dans la file : l'adresse est une saisie,
 * peut-être celle d'un tiers, et ne doit se lire ni dans la file ni parmi les
 * jobs échoués.
 *
 * Le courtier du conteneur pose le plancher commun des réponses
 * d'authentification (`auth.timebox_duration`, une seconde) autour de
 * `sendResetLink()`, sans jamais rendre la main tôt : il endormirait le
 * travailleur une seconde par demande, pour une durée que personne ne voit.
 * Le job prend donc un courtier neuf, créé sans plancher : la valeur ne
 * change que le temps de sa création, et redevient celle de la configuration
 * pour toute autre lecture.
 */
final class EnvoyerLeLienDeReinitialisation implements ShouldBeEncrypted, ShouldQueue
{
    use Localizable;
    use Queueable;

    public function __construct(public string $adresse, public string $langue)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $this->withLocale(
            $this->langue,
            fn (): string => self::courtierSansPlancher()->sendResetLink(['email' => $this->adresse]),
        );
    }

    /**
     * Un courtier des mots de passe neuf, dont la Timebox ne dort pas : il lit
     * `auth.timebox_duration` à sa création, posée à 0 le temps de celle-ci.
     */
    private static function courtierSansPlancher(): PasswordBroker
    {
        $plancher = config('auth.timebox_duration');
        config(['auth.timebox_duration' => 0]);

        try {
            return new PasswordBrokerManager(app())->broker();
        } finally {
            config(['auth.timebox_duration' => $plancher]);
        }
    }
}
