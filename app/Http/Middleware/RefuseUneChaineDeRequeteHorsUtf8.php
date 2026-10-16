<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse en 400, avant toute route, une chaîne de requête qui n'est pas de
 * l'UTF-8 valide (#2071), comme Laravel refuse déjà un tel chemin
 * (`ValidatePathEncoding`, même exception, même réponse).
 *
 * PHP décode `%FF` en un octet que rien ne vérifie, et ce qui recopie la
 * valeur dans du JSON ne sait pas l'écrire : la page des statistiques, qui
 * recopie sa période, partait vide au premier chargement (le JSON de la page
 * manquait) et répondait 500 à une visite Inertia ; le panneau répondait 500
 * dès qu'une recherche, un tri, un onglet ou un filtre en portait un, Livewire
 * ne sachant pas écrire l'état du composant. L'application n'écrit jamais une
 * telle adresse : seule une adresse forgée ou abîmée en porte, et le refus le
 * dit plutôt que de répondre à côté.
 *
 * Clefs et valeurs sont vérifiées, à toute profondeur (`mb_check_encoding()`
 * parcourt un tableau). Le corps d'une requête n'est pas lu : un corps JSON
 * ne peut pas porter un tel octet, et un formulaire se valide champ par champ.
 * En fin de pile globale (`append`), après les maillons de Laravel, que la
 * valeur ne gêne pas, et hors des groupes : il refuse aussi pour le panneau,
 * Livewire et les outils d'administration, qui n'ont pas le groupe `web`.
 * Rien d'une requête à l'autre sous Octane.
 */
final class RefuseUneChaineDeRequeteHorsUtf8
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws MalformedUrlException Si la chaîne de requête n'est pas de l'UTF-8.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! mb_check_encoding($request->query->all(), 'UTF-8')) {
            throw new MalformedUrlException();
        }

        return $next($request);
    }
}
