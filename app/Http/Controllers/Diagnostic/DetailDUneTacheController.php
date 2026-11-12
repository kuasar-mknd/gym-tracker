<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/taches/{identifiant}`, sous la portée `Sante` (#2032) : une
 * tâche planifiée et ses cinquante derniers échecs des sept derniers jours.
 * Le message de chacun, sous `non_fiable`, avec `TexteBrut` seulement : sans
 * elle la clé n'existe pas. Une tâche que le moniteur ne connaît pas : 404.
 */
final class DetailDUneTacheController extends DiagnosticController
{
    public function __invoke(Request $request, string $identifiant): Response
    {
        $maintenant = now();
        $this->parametres($request, ['identifiant' => (int) $identifiant]);
        $texteBrut = in_array(PorteeDeDiagnostic::TexteBrut->value, $this->jeton($request)->portees, true);

        return $this->servir($request, function () use ($identifiant, $maintenant, $texteBrut): Response|EnveloppeRendue {
            $donnees = ['tache_planifiee' => new ProjectionDesTachesPlanifiees()->detail((int) $identifiant, FenetreDeDiagnostic::depuis(null, $maintenant), $texteBrut)];

            return $donnees['tache_planifiee'] === null
                ? $this->introuvable()
                : new EnveloppeDeDiagnostic()($donnees, $maintenant, echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'));
        });
    }
}
