<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\flushHeaders;

/**
 * Tout ce qu'un client peut lire d'une réponse : statut, en-têtes, cookies,
 * corps, session, puis la page qu'il retrouve après la redirection (#1951).
 *
 * Deux réponses qui ne doivent pas dire si une adresse a un compte se
 * comparent par ce relevé, en entier. Seuls les jetons tirés au hasard à
 * chaque requête en sont ôtés : la valeur des cookies, le jeton CSRF de la
 * session et le nonce de la CSP. L'en-tête `Date` et le `Max-Age` des cookies
 * suivent l'horloge du système, que `freezeSecond()` n'arrête pas : un cookie
 * se lit par ses attributs, dont son échéance. Les erreurs de la session se
 * lisent en tableaux : un sac d'erreurs est un objet, et deux objets distincts
 * ne sont jamais identiques.
 */
final class CeQueLeClientLit
{
    /**
     * @param  TestResponse<Response>  $reponse
     * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
     */
    public static function de(TestResponse $reponse, string $pageSuivante): array
    {
        $enTetes = $reponse->headers->allPreserveCaseWithoutCookies();
        unset($enTetes['Date']);
        $enTetes['Content-Security-Policy'] = preg_replace("/'nonce-[^']+'/", "'nonce-…'", (string) $reponse->headers->get('Content-Security-Policy'));

        $cookies = array_values(array_map(static fn (Cookie $cookie): array => [
            'nom' => $cookie->getName(),
            'expire' => $cookie->getExpiresTime(),
            'chemin' => $cookie->getPath(),
            'domaine' => $cookie->getDomain(),
            'securise' => $cookie->isSecure(),
            'http' => $cookie->isHttpOnly(),
            'site' => $cookie->getSameSite(),
        ], $reponse->headers->getCookies()));

        $session = session()->all();
        unset($session['_token']);

        if (($session['errors'] ?? null) instanceof ViewErrorBag) {
            $session['errors'] = array_map(static fn (MessageBag $erreurs): array => $erreurs->toArray(), $session['errors']->getBags());
        }

        return [
            'statut' => $reponse->getStatusCode(),
            'entetes' => $enTetes,
            'cookies' => $cookies,
            'corps' => $reponse->getContent(),
            'session' => $session,
            'page' => json_encode(flushHeaders()->get($pageSuivante)->inertiaPage(), JSON_THROW_ON_ERROR),
        ];
    }
}
