<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Refuse en 400, avant toute route, une chaîne de requête ou un formulaire
 * qui n'est pas de l'UTF-8 valide (#2071), comme Laravel refuse déjà un tel
 * chemin (`ValidatePathEncoding`, `MalformedUrlException`).
 *
 * PHP décode `%FF` en un octet que rien ne vérifie. Dans la chaîne de
 * requête, ce qui recopie la valeur dans du JSON ne sait pas l'écrire : la
 * page des statistiques, qui recopie sa période, partait vide au premier
 * chargement (le JSON de la page manquait) et répondait 500 à une visite
 * Inertia ; le panneau répondait 500 dès qu'une recherche, un tri, un onglet
 * ou un filtre en portait un, Livewire ne sachant pas écrire l'état du
 * composant. Dans un formulaire (`application/x-www-form-urlencoded` ou
 * `multipart/form-data`), `string` et `max` ne vérifient pas l'encodage :
 * la valeur allait jusqu'à MySQL, qui la refuse en mode strict, et
 * l'écriture répondait 500. L'application n'envoie jamais un tel octet :
 * seule une requête forgée ou abîmée en porte, et le refus le dit plutôt
 * que de répondre à côté.
 *
 * Le critère est celui des lecteurs : clefs et valeurs, à toute profondeur,
 * doivent pouvoir s'écrire en JSON, ce que `json_encode()` refuse d'un
 * octet hors UTF-8 (l'imbrication, que PHP borne à 64 niveaux en lisant la
 * requête, reste loin des 512 de JSON). Un corps JSON n'est pas relu : PHP
 * refuse de décoder un JSON hors UTF-8, et le relire en JSON refuserait à
 * tort un nombre que le décodage a rendu infini (`1e999`), que la
 * validation refuse déjà sur son champ. Les fichiers envoyés ne sont pas
 * lus, ni les en-têtes : un lecteur d'en-tête vérifie lui-même sa forme
 * (`CreateSetAction::idempotencyKey()`).
 *
 * En fin de pile globale (`append`), après les maillons de Laravel, que la
 * valeur ne gêne pas, et hors des groupes : il refuse aussi pour le panneau,
 * Livewire et les outils d'administration, qui n'ont pas le groupe `web`.
 * Rien d'une requête à l'autre sous Octane.
 */
final class RefuseUneRequeteHorsUtf8
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws MalformedUrlException Si la chaîne de requête n'est pas de l'UTF-8.
     * @throws BadRequestHttpException Si le formulaire n'est pas de l'UTF-8.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (json_encode($request->query->all()) === false) {
            throw new MalformedUrlException();
        }

        if (! $request->isJson() && json_encode($request->request->all()) === false) {
            throw new BadRequestHttpException('Malformed request body.');
        }

        return $next($request);
    }
}
