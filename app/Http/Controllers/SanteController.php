<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ce que `/up` répond aux sondes de santé : `{"status":"up"}` et 200, ou
 * `{"status":"down"}` et 500 quand un écouteur de `DiagnosingHealth` lève.
 *
 * La route de santé de Laravel rendait sa propre page HTML, qui chargeait une
 * fonte et un script depuis d'autres sites, sans Content-Security-Policy. Les
 * sondes n'en lisent que le statut : `curl -f` du `HEALTHCHECK` de l'image et
 * de `docker-compose.prod.yml`, le job `demarrage` de la CI.
 *
 * Ce contrôleur reprend le reste de cette route : le même chemin, le même
 * évènement (`SanteServiceProvider` y réannonce la version de `app`), la même
 * exception relancée en mode debug, signalée et changée en 500 sinon, et le
 * même corps que Laravel donnait déjà à qui demande du JSON, désormais à tout
 * le monde. Autre différence : une exception sans message compte comme une
 * panne, quand Laravel prenait son message vide pour l'absence d'erreur et
 * répondait 200. Le message ne sort jamais dans la réponse.
 *
 * Hors des groupes `web` et `api` (`bootstrap/app.php`) : ni session, ni
 * cookie, et la maintenance ne la ferme pas. Le contrôleur ne lit ni la base
 * ni le cache ; seul l'écouteur de `SanteServiceProvider` réécrit l'annonce de
 * version dans le cache, comme avant. La réponse porte sa propre politique,
 * qui n'autorise rien, et `no-store` ; les autres en-têtes de sécurité,
 * `nosniff` et `X-Frame-Options` compris, viennent de `SecurityHeaders`, comme
 * pour toute réponse.
 */
final class SanteController extends Controller
{
    /**
     * Rien à charger, rien à exécuter, ni base ni formulaire : la réponse
     * n'est pas une page. Le cadrage reste réglé par `X-Frame-Options`.
     */
    public const string POLITIQUE = "default-src 'none'; base-uri 'none'; form-action 'none'";

    /**
     * @throws Throwable en mode debug, l'exception d'un écouteur, telle quelle.
     */
    public function __invoke(): JsonResponse
    {
        $enPanne = false;

        try {
            Event::dispatch(new DiagnosingHealth());
        } catch (Throwable $erreur) {
            if (app()->hasDebugModeEnabled()) {
                throw $erreur;
            }

            report($erreur);

            $enPanne = true;
        }

        return response()->json(
            ['status' => $enPanne ? 'down' : 'up'],
            $enPanne ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_OK,
            [
                'Cache-Control' => 'no-store',
                'Content-Security-Policy' => self::POLITIQUE,
            ],
        );
    }
}
