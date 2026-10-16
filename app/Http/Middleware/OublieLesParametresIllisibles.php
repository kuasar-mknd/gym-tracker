<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retire de la requête les paramètres que des paquets lisent sans en
 * vérifier la forme, quand ils n'ont pas celle qu'ils savent lire (#2071) :
 * le paquet retombe alors sur sa valeur par défaut, comme pour un paramètre
 * absent.
 *
 * Les paquets les lisent par `input()`, qui cherche d'abord dans le corps
 * puis dans la chaîne de requête : un corps JSON, même porté par un GET, y
 * passe avant elle. Le maillon nettoie donc la chaîne de requête et le corps,
 * formulaire ou JSON, que Laravel range dans `$request->request`
 * (`createFromBase()`).
 *
 * - `page`, que lisent la pagination de Laravel (pages Inertia) et celle de
 *   Livewire (tables du panneau) : un entier de 1 à `PAGE_AU_PLUS`, en
 *   chiffres ou, dans un corps JSON, en nombre entier. Les deux
 *   prennent tout entier positif, et `forPage()` multiplie la page par la
 *   taille de la page : au-delà de ce qu'un entier tient, PHP 8.5 avertit
 *   que le décalage n'en est plus un, et la liste répondait 500 dès qu'elle
 *   avait une ligne (`?page=9223372036854775807`). Une page qu'ils ne lisaient
 *   pas déjà (`page[]=2`, `page=abc`) était la première : elle l'est encore.
 * - `search`, que les tables de Filament recopient par `strval()` : une
 *   chaîne. Un tableau (`?search[]=x`) répondait 500 sur chaque liste du
 *   panneau.
 *
 * Ce qu'une page de l'application lit, elle le lit elle-même dans la forme
 * attendue (`.ai/rules/controllers.md`) ; ce maillon ne couvre que les
 * lecteurs qu'elle ne peut pas changer. En fin de pile globale, pour le
 * panneau et Livewire comme pour le groupe `web`. Rien d'une requête à
 * l'autre sous Octane : il ne garde rien, et ne pose aucun résolveur de
 * page, qu'Octane remettrait à celui de Laravel à chaque requête
 * (`GiveNewRequestInstanceToPaginator`).
 */
final class OublieLesParametresIllisibles
{
    /**
     * Un million de pages : vingt millions de lignes à vingt par page, bien
     * au-delà de ce qu'un compte enregistre, et un décalage qui reste un
     * entier tant qu'une page compte moins de neuf mille milliards de lignes.
     */
    public const int PAGE_AU_PLUS = 1_000_000;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::sacsLus($request) as $sac) {
            $parametres = $sac->all();

            if (array_key_exists('page', $parametres) && ! self::pageLisible($parametres['page'])) {
                $sac->remove('page');
            }

            if (array_key_exists('search', $parametres) && ! is_string($parametres['search'])) {
                $sac->remove('search');
            }
        }

        return $next($request);
    }

    /**
     * Les sacs où `input()` cherche : la chaîne de requête, le corps, et le
     * corps JSON s'il n'est pas déjà celui-là.
     *
     * @return list<ParameterBag>
     */
    private static function sacsLus(Request $request): array
    {
        $sacs = [$request->query, $request->request];
        $json = $request->isJson() ? $request->json() : null;

        if ($json instanceof ParameterBag && $json !== $request->request) {
            $sacs[] = $json;
        }

        return $sacs;
    }

    /**
     * Un entier de 1 à `PAGE_AU_PLUS`, en chiffres ou en nombre entier.
     */
    private static function pageLisible(mixed $page): bool
    {
        return (is_string($page) || is_int($page))
            && filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::PAGE_AU_PLUS]]) !== false;
    }
}
