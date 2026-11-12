<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\JetonDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\Projections\LecturesBornees;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

/**
 * La base des lectures du diagnostic (#2032) : le jeton que la garde a
 * reconnu, et l'enveloppe rendue telle quelle en JSON.
 */
abstract class DiagnosticController extends Controller
{
    protected function jeton(Request $request): JetonDeDiagnostic
    {
        $jeton = $request->attributes->get(ExigeUnJetonDeDiagnostic::JETON);

        return $jeton instanceof JetonDeDiagnostic ? $jeton : throw new LogicException('La garde du diagnostic n\'a reconnu aucun jeton.');
    }

    /**
     * Ce que la lecture rend, ou la 503 quand la base l'a coupée à la borne
     * de temps (`LecturesBornees`). Toute autre erreur de la base remonte.
     *
     * @param  Closure(): (EnveloppeRendue|Response)  $lecture  l'enveloppe à rendre, ou la réponse d'un refus
     */
    protected function servir(Request $request, Closure $lecture): Response
    {
        try {
            $rendue = $lecture();

            return $rendue instanceof Response ? $rendue : $this->repondre($request, $rendue);
        } catch (QueryException $erreur) {
            return LecturesBornees::tempsEcoule($erreur)
                ? $this->refus(503, 'lecture-trop-longue')
                : throw $erreur;
        }
    }

    /**
     * Les paramètres validés de la lecture, pour la ligne d'audit : des
     * nombres, des instants et une empreinte (32 chiffres hexadécimaux, que la
     * route contraint), jamais un texte venu de la requête.
     *
     * @param  array<string, int|string|null>  $parametres
     */
    protected function parametres(Request $request, array $parametres): void
    {
        $this->poser($request, ExigeUnJetonDeDiagnostic::PARAMETRES, $parametres);
    }

    protected function introuvable(): Response
    {
        return $this->refus(404, 'introuvable');
    }

    private function refus(int $statut, string $erreur): Response
    {
        return new Response((string) json_encode(['erreur' => $erreur, 'note' => EnveloppeDeDiagnostic::NOTE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $statut, ['Content-Type' => 'application/json']);
    }

    protected function repondre(Request $request, EnveloppeRendue $rendue): Response
    {
        $corps = $rendue->json();

        throw_if(strlen($corps) >= EnveloppeRendue::OCTETS_AU_PLUS, LogicException::class, 'Une réponse du diagnostic dépasse 256 Kio.');

        $this->poser($request, ExigeUnJetonDeDiagnostic::ELEMENTS, count($rendue->enveloppe['donnees']));

        return new Response($corps, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Ce que la garde lira pour l'audit, sur sa requête : une requête de
     * validation que le contrôleur reçoit en est une copie, faite avant lui.
     *
     * @param  int|array<string, int|string|null>  $valeur
     */
    private function poser(Request $request, string $cle, int|array $valeur): void
    {
        $request->attributes->set($cle, $valeur);
        request()->attributes->set($cle, $valeur);
    }
}
