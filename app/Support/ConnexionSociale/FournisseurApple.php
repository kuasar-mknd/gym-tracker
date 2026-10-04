<?php

declare(strict_types=1);

namespace App\Support\ConnexionSociale;

use SocialiteProviders\Apple\Provider;

/**
 * Le pilote « Continuer avec Apple », sans session et avec son nonce dans un
 * cookie (#1911).
 *
 * Apple renvoie l'utilisateur par un POST venu de son propre site
 * (`response_mode=form_post`, imposé dès qu'on demande le nom ou l'adresse).
 * Le cookie de session, en `SameSite=lax`, ne voyage pas avec ce POST : le
 * rappel arrive dans une session vide, et l'état que Socialite y avait rangé
 * au départ n'y est plus. Chaque rappel échouait donc, même configuré.
 *
 * Le pilote du paquet sait faire sans session : sans état (`stateless`), il
 * tire un nonce au départ, l'envoie à Apple et le pose chez le navigateur dans
 * un cookie chiffré, `Secure`, `HttpOnly` et `SameSite=None`, le seul qui
 * survive au POST d'Apple (`cookieNonce()`). Au retour, il relit ce cookie et
 * exige que le jeton d'identité signé par Apple porte le même nonce : un rappel
 * que ce navigateur n'a pas lancé, ou rejoué ailleurs, est refusé. Le reste de
 * l'application garde ses cookies en `lax`.
 *
 * Les deux réglages sont ici, posés à la construction, plutôt qu'enchaînés à
 * chaque `Socialite::driver('apple')` : le départ et le retour doivent les
 * avoir tous les deux, et un appel qui en oublierait un échouerait sans bruit
 * (un départ avec session envoie un nonce que le retour sans session ne relit
 * pas, et l'inverse lève « A stateless Apple callback has no CSRF protection »).
 * `AppServiceProvider` annonce cette classe à Socialite sous le nom `apple`.
 */
final class FournisseurApple extends Provider
{
    /**
     * Le cookie qui garde le nonce entre le départ et le retour.
     *
     * Le paquet le chiffre lui-même avec `APP_KEY` ; `AppServiceProvider` le
     * retire donc d'`EncryptCookies`, qui le chiffrait une seconde fois : 827
     * octets au lieu de 340 environ, et la redirection vers Apple dépassait le
     * budget d'en-têtes que le proxy inverse impose (EnTetesDeReponseTest).
     */
    public const string COOKIE_DU_NONCE = self::STATELESS_NONCE_COOKIE;

    /**
     * Pas d'état en session : le POST d'Apple arrive sans le cookie de session.
     *
     * @var bool
     */
    #[\Override]
    protected $stateless = true;

    /**
     * Le nonce voyage dans le cookie chiffré du paquet, à la place de l'état.
     *
     * @var bool
     */
    #[\Override]
    protected $carryNonceInCookie = true;
}
