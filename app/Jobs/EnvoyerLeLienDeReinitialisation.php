<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;
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
            fn (): string => Password::sendResetLink(['email' => $this->adresse]),
        );
    }
}
