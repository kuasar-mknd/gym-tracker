<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CleDesCopiesEnSession;
use App\Support\RappelDeLAppareil;
use Closure;
use DateTimeInterface;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as Authentification;
use Illuminate\Contracts\Session\Session;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le témoin des copies hors ligne : un cookie que la page lit, porteur de la
 * clé qui chiffrera les copies des pages gardées pour la salle, et que le
 * serveur pose et efface avec la session du compte (#2048, partie C).
 *
 * Les copies d'un compte sont chiffrées sur l'appareil par la clé que porte
 * ce cookie, et ne se déchiffrent qu'avec lui. Quand la session finit, le
 * cookie part, et les copies deviennent illisibles sans qu'aucun code de la
 * page ait besoin de tourner : effacé par la réponse qui dit la fin de la
 * session, ou jeté par le navigateur à son échéance, celle du cookie de
 * session. La clé elle-même vit dans la session (`CleDesCopiesEnSession`),
 * neuve à chaque session.
 *
 * La décision se prend APRÈS `$next`, sur la réponse, dans cet ordre :
 *
 * - une réponse qui dit la fin de la session (401, 419, renvoi vers la page de
 *   connexion) efface le témoin s'il était là, et oublie la clé ;
 * - un compte présent sur la garde `web` le pose, ou le reconduit, avec son
 *   échéance ;
 * - une session qui tient la connexion d'un compte que la requête n'a pas lue
 *   le laisse tel quel (`CleDesCopiesEnSession::tientUneConnexionNonLue()`) :
 *   Pulse et le lecteur de journaux ne lisent que l'administrateur du panneau,
 *   et ne disent rien du compte ;
 * - un invité l'efface s'il était là, et ne reçoit rien sinon.
 *
 * Le compte se lit par `getUser()`, celui que la requête a déjà résolu, comme
 * `hasUser()`, jamais par `user()`, qui connecterait par le cookie « se
 * souvenir de moi ». La requête l'a toujours résolu quand elle en avait un :
 * `auth`, `auth:sanctum`, `guest`, et `AuthentifieLaSessionDuCompte` sur toute
 * route du groupe `web` dont la garde par défaut est `web`.
 *
 * Sa place dans la pile compte (bootstrap/app.php). `prependToPriorityList()`
 * le classe devant `AuthenticatesRequests`, donc après l'ouverture de la
 * session : il enveloppe `auth`, `auth:sanctum`, `AuthentifieLaSessionDuCompte`
 * et le `AuthenticateSession` de Sanctum, posé dans le groupe `api` juste après
 * `auth:sanctum`, et voit le renvoi ou le 401 qu'une fin de session y lève
 * (`Pipeline::carry()` fait rendre l'exception par le maillon qui enveloppe
 * celui qui la lève). Posé en tête du groupe `web`, il passe aussi devant la
 * vérification CSRF, que l'ordre de priorité ne classe pas, et voit son 419.
 * Sous `/api/v1`, cette vérification reste dans la pile que Sanctum ouvre
 * autour de lui avec la session (`EnsureFrontendRequestsAreStateful`, que
 * Sanctum classe en tête de l'ordre de priorité) : un 419 ne l'y traverse pas.
 * Il ne vient qu'à un navigateur qui n'envoie pas `Sec-Fetch-Site:
 * same-origin`, le plus souvent pour une session expirée, dont la première
 * page, d'invité, efface le témoin.
 *
 * Exclu d'`EncryptCookies` (bootstrap/app.php) : la page lit la clé telle
 * quelle. Hors des groupes `web` et `api`, rien : le panneau a sa propre pile,
 * `/up` et `/sonde` n'en ont aucune. Horizon et Telescope, servis par `web`, le
 * reconduisent pour le compte de l'application que la session tient, comme
 * toute page, et les mises à jour de Livewire aussi : elles passent par `web`
 * avant les middlewares du panneau.
 *
 * Rien d'une requête à l'autre sous Octane : ni propriété statique, ni
 * singleton ; la garde et le bocal à cookies viennent du conteneur de la
 * requête.
 */
final readonly class CleDesCopiesHorsLigne
{
    /**
     * Le nom du témoin hors HTTPS, là où le cookie de session n'est pas
     * réservé à HTTPS (`session.secure` faux).
     */
    public const string TEMOIN = 'gt_copies';

    /**
     * Le nom du témoin en HTTPS : le préfixe `__Host-` fait refuser par le
     * navigateur un cookie qui ne serait pas `Secure`, posé sur `/`, sans
     * domaine.
     */
    public const string TEMOIN_EN_HTTPS = '__Host-gt_copies';

    /**
     * L'échéance du témoin quand l'appareil se souvient du compte (« se
     * souvenir de moi »), décision du propriétaire (#2048) : douze heures,
     * quand le cookie de rappel, lui, vit bien plus longtemps.
     */
    public const int MINUTES_AVEC_LE_RAPPEL = 720;

    /**
     * Les statuts d'une réponse qui dit que la session ne tient plus : une
     * requête refusée faute de compte (401), un jeton CSRF qui ne correspond
     * plus à la session (419).
     */
    private const array STATUTS_DE_FIN_DE_SESSION = [Response::HTTP_UNAUTHORIZED, 419];

    public function __construct(
        private Authentification $authentification,
        private CookieJar $cookies,
    ) {
    }

    /**
     * Décide sur la réponse, dans l'ordre du docbloc de la classe. La fin de la
     * session se lit à un des statuts de fin de session, ou au renvoi vers la
     * page de connexion, que `auth` et `AuthentifieLaSessionDuCompte` font
     * d'une session expirée, fermée ou dont le compte n'existe plus.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);
        $garde = $this->authentification->guard(AuthentifieLaSessionDuCompte::GARDE);
        $session = CleDesCopiesEnSession::de($request);

        if (! $garde instanceof SessionGuard) {
            return $reponse;
        }

        $compte = $garde->getUser();

        return match (true) {
            in_array($reponse->getStatusCode(), self::STATUTS_DE_FIN_DE_SESSION, true),
            $reponse->isRedirect(route('login')) => self::effacer($request, $reponse, $session),
            $compte instanceof User => $this->poser($request, $reponse, $garde, $compte, $session),
            CleDesCopiesEnSession::tientUneConnexionNonLue($session, $garde) => $reponse,
            default => self::effacer($request, $reponse, $session),
        };
    }

    /**
     * Le nom du témoin, selon que le cookie de session est réservé à HTTPS.
     */
    public static function nom(): string
    {
        return self::enHttps() ? self::TEMOIN_EN_HTTPS : self::TEMOIN;
    }

    /**
     * Pose le témoin, ou le reconduit avec une échéance repoussée, comme
     * Laravel repousse celle du cookie de session à chaque réponse.
     */
    private function poser(Request $request, Response $reponse, SessionGuard $garde, User $compte, ?Session $session): Response
    {
        $cle = CleDesCopiesEnSession::duCompte($session, (string) $compte->id);

        if ($cle !== null) {
            $reponse->headers->setCookie(Cookie::create(
                name: self::nom(),
                value: $cle,
                expire: $this->echeance($request, $garde, $compte),
                path: '/',
                secure: self::enHttps(),
                httpOnly: false,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $reponse;
    }

    /**
     * Celle du cookie de session : `session.lifetime` minutes, ou la fin de la
     * session du navigateur quand `session.expire_on_close` est vrai, sans
     * `Expires` ; douze heures quand l'appareil se souvient du compte.
     */
    private function echeance(Request $request, SessionGuard $garde, User $compte): DateTimeInterface|int
    {
        return match (true) {
            RappelDeLAppareil::resteApresLaReponse($request, $garde, $compte, $this->cookies) => now()->addMinutes(self::MINUTES_AVEC_LE_RAPPEL),
            (bool) config('session.expire_on_close') => 0,
            default => now()->addMinutes(config()->integer('session.lifetime')),
        };
    }

    /**
     * Efface le témoin s'il était là, et oublie la clé : la session suivante
     * en tirera une neuve. Une réponse faite à qui ne l'avait pas n'en porte
     * aucune trace.
     */
    private static function effacer(Request $request, Response $reponse, ?Session $session): Response
    {
        CleDesCopiesEnSession::oublier($session);

        if ($request->cookies->has(self::nom())) {
            $reponse->headers->clearCookie(self::nom(), '/', null, self::enHttps(), false, Cookie::SAMESITE_LAX);
        }

        return $reponse;
    }

    /**
     * Si le témoin est réservé à HTTPS, comme le cookie de session.
     */
    private static function enHttps(): bool
    {
        return (bool) config('session.secure');
    }
}
