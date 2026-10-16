<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retire de la chaîne de requête les paramètres que des paquets lisent sans
 * en vérifier la forme, quand ils n'ont pas celle qu'ils savent lire (#2071) :
 * le paquet retombe alors sur sa valeur par défaut, comme pour un paramètre
 * absent.
 *
 * - `page`, que lisent la pagination de Laravel (pages Inertia) et celle de
 *   Livewire (tables du panneau) : un entier de 1 à `PAGE_AU_PLUS`. Les deux
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
        if ($request->query->has('page') && ! self::pageLisible($request->query->all()['page'])) {
            $request->query->remove('page');
        }

        if ($request->query->has('search') && ! is_string($request->query->all()['search'])) {
            $request->query->remove('search');
        }

        return $next($request);
    }

    /**
     * Un entier écrit en chiffres, de 1 à `PAGE_AU_PLUS`.
     */
    private static function pageLisible(mixed $page): bool
    {
        return is_string($page)
            && filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::PAGE_AU_PLUS]]) !== false;
    }
}
