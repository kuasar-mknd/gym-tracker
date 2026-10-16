<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Ce que la page demande pour savoir si le serveur répond de nouveau (#2048).
 *
 * Tant que le serveur ne répondait pas, « Recharger », « Modèle » et les
 * réglages de la séance restaient désactivés jusqu'à la réponse d'une autre
 * requête, même une fois le réseau revenu. La page demande donc cette adresse
 * au retour du réseau, puis de loin en loin tant qu'il ne répond pas
 * (`resources/js/Utils/sondeDuServeur.js`).
 *
 * Une réponse vide, que rien ne garde : ni session, ni base, ni cache, ni
 * compte. La route vit hors du groupe `web` (`bootstrap/app.php`), comme
 * `/up`, qui n'aurait pas convenu : chaque sondage de `/up` réannonce la
 * version du conteneur dans le cache (`SanteServiceProvider`), et `/up`
 * répond même pendant une maintenance, quand aucune page ne répond.
 */
final class SondeController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
