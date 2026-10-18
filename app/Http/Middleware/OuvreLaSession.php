<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\EcheanceDuCookie;
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
 * L'ouverture de la session du groupe `web`, à une exception près : un GET
 * que le préchauffage des copies hors ligne marque lit la session sans
 * jamais l'enregistrer, et sa réponse ne pose aucun cookie (#2048, partie D).
 *
 * Laravel enregistre la session à la fin de chaque requête, telle qu'il l'a
 * lue en entrant, et en renvoie le cookie. Le préchauffage
 * (`resources/js/Utils/prechauffage.js`) demande jusqu'à huit pages en
 * arrière-plan, l'une après l'autre, et redemande la séance en cours après un
 * rejeu : ces GET, que la personne ne voit pas, ne doivent ni prolonger la
 * session, ce que l'issue exclut, ni toucher à une session qui a changé
 * pendant qu'ils tournaient (une déconnexion, une connexion dans un autre
 * onglet), ni rendre au navigateur un cookie qu'une autre réponse a remplacé
 * depuis. Ils portent `X-Copies-Hors-Ligne: prechauffage`.
 *
 * Sur un GET ou un HEAD qui porte cette marque :
 *
 * - la session s'ouvre et se lit comme ailleurs, et la requête la voit telle
 *   qu'elle est (compte, jeton, clé des copies) ;
 * - elle n'est jamais enregistrée : ni réécrite, ni prolongée, ni créée pour
 *   un invité, et ni l'adresse de la page ni `url.intended` n'y entrent ;
 * - sa réponse ne pose aucun cookie, ni celui de la session, ni `XSRF-TOKEN`,
 *   ni le témoin des copies, et les cookies mis en file ne partent pas non
 *   plus. Seuls passent les effacements : le témoin qu'efface la réponse qui
 *   dit la fin de la session (`CleDesCopiesHorsLigne`), d'où le préchauffage
 *   apprend qu'elle a pris fin, et le cookie de rappel que cette fin efface.
 *
 * Toute autre requête suit `StartSession` tel quel. La marque ne sert qu'à
 * qui la pose : elle ne change que le sort de la session de la requête qui
 * la porte.
 *
 * Branché à la place de `StartSession` dans le groupe `web`
 * (bootstrap/app.php, `web(replace:)`) : l'ordre de priorité de Laravel le
 * classe à la même place, par sa classe parente. Le panneau, hors des
 * groupes, garde le `StartSession` de Laravel : le préchauffage ne le demande
 * jamais.
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
     * Ouvre la session ; sur un GET marqué, la lit sans l'enregistrer et
     * retire de la réponse tout cookie qu'elle pose.
     *
     * @param  Session  $session
     */
    #[\Override]
    protected function handleStatefulRequest(Request $request, $session, Closure $next): mixed
    {
        if (! self::estUnPrechauffage($request)) {
            return parent::handleStatefulRequest($request, $session, $next);
        }

        $request->setLaravelSession($this->startSession($request, $session));

        $reponse = $next($request);

        if ($reponse instanceof Response) {
            $this->neRienPoser($reponse);
        }

        return $reponse;
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
