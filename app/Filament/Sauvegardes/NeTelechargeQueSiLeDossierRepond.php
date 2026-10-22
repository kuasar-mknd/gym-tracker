<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le maillon que la page « Sauvegardes » pose devant la route de
 * téléchargement du greffon, par `PageDesSauvegardes::getRouteMiddleware()` :
 * l'archive ne se lit qu'après avoir vu le dossier répondre (#1929).
 *
 * Depuis la 4.1 du greffon, « Télécharger » est un lien signé vers cette
 * route, dont le contrôleur lit le disque sans délai (existence, type et
 * taille de l'archive, puis son flux). Il ne passe plus par les tableaux, ni
 * donc par leur sonde, comme le faisait l'action de la 4.0. Sur un partage qui
 * s'endort page ouverte, ou par un lien rouvert plus tard (il n'a pas
 * d'échéance), chaque clic aurait pris un travailleur d'Octane sans fin. Le
 * maillon sonde donc d'abord, et répond 503 en disant pourquoi quand le
 * dossier se tait.
 *
 * La route de la page prend les mêmes maillons : il laisse passer toute autre
 * route, la page et ses tableaux sondant déjà. Il laisse aussi passer un lien
 * sans signature valide, que le maillon `signed`, posé après lui par le
 * greffon, refuse : un lien refusé ne coûte pas de sonde.
 */
final class NeTelechargeQueSiLeDossierRepond
{
    use SondeLeDossierDesSauvegardes;

    /**
     * La route de téléchargement du greffon, sous le nom que lui donne le
     * panneau.
     */
    private const string ROUTE_DU_TELECHARGEMENT = 'filament.*.filament-spatie-backup.download';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(self::ROUTE_DU_TELECHARGEMENT)
            || ! $request->hasValidSignature()
            || $this->dossierDesSauvegardesRepond()) {
            return $next($request);
        }

        return response()->view('errors.partials.page', [
            'code' => (string) Response::HTTP_SERVICE_UNAVAILABLE,
            'titre' => $this->titreDuDossierQuiNeRepondPas(),
            'detail' => $this->explicationDuDossierQuiNeRepondPas(),
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    protected function ceQuiNeSeFaitPasSansLeDossier(): string
    {
        return "l'archive n'est pas servie";
    }
}
