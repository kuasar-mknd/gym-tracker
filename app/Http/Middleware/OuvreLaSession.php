<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\EcheanceDuCookie;
use App\Support\Sessions\SessionQuiNeRenaitPas;
use Closure;
use Illuminate\Contracts\Cache\Factory as FabriqueDesCaches;
use Illuminate\Contracts\Session\Session;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'ouverture de la session, partout où une requête en ouvre une : le groupe
 * `web`, la pile de Sanctum du groupe `api` et le panneau. Elle suit
 * `StartSession`, à deux exceptions près.
 *
 * Une session détruite pendant la requête (#2088). Laravel enregistre la
 * session à la fin de chaque requête, telle qu'il l'a lue en entrant, et en
 * renvoie le cookie. Une requête d'arrière-plan qui l'a lue AVANT qu'une
 * autre la détruise (une déconnexion, une connexion, la suppression du
 * compte, la déconnexion du panneau) et qui finit APRÈS la faisait renaître
 * dans le magasin de production, et rendait au navigateur l'ancien cookie,
 * par-dessus celui du compte connecté entre-temps. Le magasin
 * (`App\Support\Sessions\SessionGardee`) ne la réécrit plus et le dit ; la
 * réponse ne pose alors aucun cookie, ni celui de la session, ni
 * `XSRF-TOKEN`, ni le témoin des copies, ni un cookie mis en file. Seuls
 * passent les effacements. Une requête qui n'a croisé aucune destruction
 * enregistre et prolonge sa session comme Laravel.
 *
 * Un GET du préchauffage des copies hors ligne (#2048, partie D). Le
 * préchauffage (`resources/js/Utils/prechauffage.js`) demande jusqu'à huit
 * pages en arrière-plan, l'une après l'autre, et redemande la séance en cours
 * après un rejeu : ces GET, que la personne ne voit pas, ne doivent pas
 * prolonger la session, ce que l'issue exclut. Ils portent
 * `X-Copies-Hors-Ligne: prechauffage`. Sur un GET ou un HEAD qui porte cette
 * marque :
 *
 * - la session s'ouvre et se lit comme ailleurs, et la requête la voit telle
 *   qu'elle est (compte, jeton, clé des copies) ;
 * - elle n'est jamais enregistrée : ni réécrite, ni prolongée, ni créée pour
 *   un invité, et ni l'adresse de la page ni `url.intended` n'y entrent ;
 * - sa réponse ne pose aucun cookie, comme celle d'une requête qui a croisé
 *   une destruction : seuls passent les effacements, dont le témoin
 *   qu'efface la réponse qui dit la fin de la session (`CleDesCopiesHorsLigne`),
 *   d'où le préchauffage apprend qu'elle a pris fin.
 *
 * La marque ne sert qu'à qui la pose : elle ne change que le sort de la
 * session de la requête qui la porte.
 *
 * Branché à la place de `StartSession` dans le groupe `web`
 * (bootstrap/app.php, `web(replace:)`) : l'ordre de priorité de Laravel le
 * classe à la même place, par sa classe parente. Le panneau le nomme dans sa
 * pile (`AdminPanelProvider`). Et `AppServiceProvider` le lie à
 * `StartSession` dans le conteneur : Sanctum écrit `StartSession` en dur dans
 * la pile qu'il ouvre pour une requête de la page, que rien d'autre
 * n'atteint.
 *
 * Rien d'une requête à l'autre sous Octane : ni propriété statique, ni
 * singleton ; le gestionnaire des sessions, les caches et le bocal à cookies
 * viennent du conteneur de la requête.
 */
final class OuvreLaSession extends StartSession
{
    /**
     * L'en-tête que portent les GET du préchauffage, et la redemande de la
     * séance (`ENTETE_DU_PRECHAUFFAGE` de `prechauffage.js`).
     */
    public const string ENTETE = 'X-Copies-Hors-Ligne';

    /**
     * Sa valeur (`MARQUE_DU_PRECHAUFFAGE` de `prechauffage.js`).
     */
    public const string PRECHAUFFAGE = 'prechauffage';

    public function __construct(
        SessionManager $manager,
        FabriqueDesCaches $caches,
        private readonly CookieJar $cookies,
    ) {
        parent::__construct($manager, static fn (): FabriqueDesCaches => $caches);
    }

    /**
     * Ouvre la session, et l'enregistre à la fin comme Laravel, sauf sur un
     * GET marqué du préchauffage ou quand elle a été détruite ailleurs
     * pendant la requête : la réponse ne pose alors aucun cookie.
     *
     * @param  Session  $session
     */
    #[\Override]
    protected function handleStatefulRequest(Request $request, $session, Closure $next): mixed
    {
        $request->setLaravelSession($this->startSession($request, $session));

        if (self::estUnPrechauffage($request)) {
            return $this->sansRienPoser($next($request));
        }

        $this->collectGarbage($session);

        $reponse = $next($request);

        $this->storeCurrentUrl($request, $session);

        if (! $this->enregistrer($request, $session)) {
            return $this->sansRienPoser($reponse);
        }

        if ($reponse instanceof Response) {
            $this->addCookieToResponse($reponse, $session);
        }

        return $reponse;
    }

    /**
     * Enregistre la session comme Laravel (rien pour une requête
     * précognitive), et dit si la réponse peut en poser le cookie : non quand
     * la session que la requête a lue a été détruite ailleurs entre-temps.
     * La session est celle du gestionnaire, que Laravel enregistre
     * (`saveSession()`).
     */
    private function enregistrer(Request $request, Session $session): bool
    {
        if ($request->isPrecognitive()) {
            return true;
        }

        if ($session instanceof SessionQuiNeRenaitPas) {
            return $session->enregistrerSiElleTient();
        }

        $session->save();

        return true;
    }

    /**
     * Un GET ou un HEAD qui porte la marque du préchauffage.
     */
    private static function estUnPrechauffage(Request $request): bool
    {
        return in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)
            && $request->headers->get(self::ENTETE) === self::PRECHAUFFAGE;
    }

    /**
     * La réponse, sans aucun des cookies qu'elle pose (`neRienPoser()`).
     */
    private function sansRienPoser(mixed $reponse): mixed
    {
        if ($reponse instanceof Response) {
            $this->neRienPoser($reponse);
        }

        return $reponse;
    }

    /**
     * Retire de la réponse, et de la file des cookies, tout cookie qui prend
     * une valeur ; laisse ceux qui en effacent un. L'échéance se juge à
     * l'horloge de l'application, celle qui l'a calculée (`EcheanceDuCookie`).
     */
    private function neRienPoser(Response $reponse): void
    {
        $maintenant = Carbon::now();

        foreach ($reponse->headers->getCookies() as $cookie) {
            if (! EcheanceDuCookie::estPassee($cookie, $maintenant)) {
                $reponse->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            if (! EcheanceDuCookie::estPassee($cookie, $maintenant)) {
                $this->cookies->unqueue($cookie->getName(), $cookie->getPath());
            }
        }
    }
}
