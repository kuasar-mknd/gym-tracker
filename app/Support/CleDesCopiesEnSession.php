<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * La clé des copies hors ligne, telle que la session du compte la garde
 * (#2048) : 32 octets tirés au hasard, en base64url, liés au compte pour
 * lequel ils ont été tirés.
 *
 * Elle naît avec la première réponse faite au compte dans la session et meurt
 * avec elle. La déconnexion et la suppression du compte vident la session,
 * comme `AuthentifieLaSessionDuCompte` et le `AuthenticateSession` de Sanctum
 * quand le mot de passe a changé ailleurs, et une session expirée n'a plus
 * rien ; `CleDesCopiesHorsLigne` l'oublie en outre sur toute réponse qui efface
 * le témoin, dont celle de `/_dusk/logout`, qui déconnecte sans vider la
 * session. La connexion suivante en tire donc une neuve. Un compte qui se
 * connecte sur une session qui garde la clé d'un autre en tire une neuve
 * aussi : la clé est liée au compte. Le même compte qui rouvre sa session sous
 * un nouveau mot de passe (`PasswordController`) garde la sienne : la session
 * continue, sur le même appareil.
 *
 * Deux premières requêtes parallèles d'une même session peuvent chacune en
 * tirer une ; la session garde la dernière enregistrée, et la réponse suivante
 * repose celle-là.
 */
final class CleDesCopiesEnSession
{
    /**
     * L'entrée de session qui porte la clé et son compte.
     */
    public const string ENTREE = 'hors_ligne';

    /**
     * La clé, en base64url.
     */
    public const string CLE = self::ENTREE.'.cle';

    /**
     * L'identifiant du compte pour lequel la clé a été tirée.
     */
    public const string COMPTE = self::ENTREE.'.compte';

    /**
     * La longueur de la clé : une clé AES de 256 bits.
     */
    private const int OCTETS = 32;

    /**
     * La session de la requête, s'il y en a une : Sanctum n'en ouvre qu'aux
     * requêtes de l'interface (`Referer` ou `Origin` de confiance).
     */
    public static function de(Request $request): ?Session
    {
        return $request->hasSession() ? $request->session() : null;
    }

    /**
     * La clé du compte dans cette session : celle qu'elle garde pour lui, ou
     * une neuve. Sans session, aucune.
     */
    public static function duCompte(?Session $session, string $compte): ?string
    {
        if ($session === null) {
            return null;
        }

        $cle = $session->get(self::CLE);

        return is_string($cle) && $session->get(self::COMPTE) === $compte ? $cle : self::tirer($session, $compte);
    }

    /**
     * Oublie la clé : la connexion suivante en tirera une neuve.
     */
    public static function oublier(?Session $session): void
    {
        $session?->forget(self::ENTREE);
    }

    /**
     * Dit si la session tient la connexion d'un compte que cette requête n'a
     * pas lue : la garde `web` n'a pas de compte, et la session en nomme un.
     *
     * C'est le cas sous Pulse et le lecteur de journaux, où le `Authenticate`
     * de Filament, qui passe avant `AuthentifieLaSessionDuCompte`, a fait de la
     * garde du panneau la garde par défaut, ou renvoie vers la connexion du
     * panneau, et où personne ne lit donc la garde `web`. C'est aussi le cas
     * d'un compte supprimé ailleurs, dont l'identifiant reste en session : une
     * page qui exige un compte le renvoie vers la connexion, et ce renvoi
     * efface le témoin.
     */
    public static function tientUneConnexionNonLue(?Session $session, SessionGuard $garde): bool
    {
        return $session?->has($garde->getName()) === true;
    }

    /**
     * Tire une clé neuve pour le compte, et la range dans la session.
     */
    private static function tirer(Session $session, string $compte): string
    {
        $cle = rtrim(strtr(base64_encode(random_bytes(self::OCTETS)), '+/', '-_'), '=');

        $session->put(self::ENTREE, ['cle' => $cle, 'compte' => $compte]);

        return $cle;
    }
}
