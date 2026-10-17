<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Ce que l'appareil garde de « se souvenir de moi » pour un compte : le cookie
 * que la garde accepterait pour le reconnecter, et non sa simple présence.
 *
 * La garde ignore sans l'effacer un cookie périmé (mot de passe changé ou
 * compte déconnecté ailleurs, cookie d'un autre compte), et une connexion sans
 * « se souvenir de moi » le laisse en place : l'ordinateur partagé où la
 * personne n'a pas coché la case peut en porter un. Le changement du mot de
 * passe (`PasswordController`) qui le réémettrait sur sa seule présence le
 * rendrait valide pour toute la durée de rappel, et la personne suivante
 * entrerait dans le compte une fois la session expirée avec le navigateur. Le
 * témoin des copies hors ligne (`CleDesCopiesHorsLigne`) donnerait de même
 * l'échéance d'un appareil qui se souvient à un appareil qui ne se souvient de
 * rien.
 */
final class RappelDeLAppareil
{
    /**
     * Dit si l'appareil porte un cookie « se souvenir de moi » que la garde
     * accepterait pour CE compte : son identifiant, son jeton de rappel, et
     * l'empreinte de son mot de passe actuel. Le cookie de la requête,
     * déchiffré par `EncryptCookies` ; absent, il ne vaut rien
     * (`Recaller::valid()` refuse la chaîne vide).
     */
    public static function vautPour(Request $request, SessionGuard $garde, User $compte): bool
    {
        $rappel = new Recaller((string) $request->cookies->get($garde->getRecallerName()));

        return $rappel->valid()
            && $rappel->id() === (string) $compte->id
            && hash_equals((string) $compte->getRememberToken(), $rappel->token())
            && hash_equals($garde->hashPasswordForCookie($compte->getAuthPassword()), $rappel->hash());
    }

    /**
     * Dit si l'appareil se souviendra du compte après la réponse : le cookie
     * que la réponse pose ou efface quand elle en met un en file (à la
     * connexion, au changement du mot de passe, à la déconnexion), sinon celui
     * que la requête apporte, s'il vaut pour ce compte.
     */
    public static function resteApresLaReponse(Request $request, SessionGuard $garde, User $compte, CookieJar $cookies): bool
    {
        $enFile = $cookies->queued($garde->getRecallerName());

        return $enFile instanceof Cookie ? ! $enFile->isCleared() : self::vautPour($request, $garde, $compte);
    }
}
