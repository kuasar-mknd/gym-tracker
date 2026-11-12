<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/taches`, sous la portée `Sante` (#2032) : les tâches
 * planifiées (au plus 50), leur état à l'instant de la lecture et leurs cinq
 * derniers échecs des sept derniers jours, lus sur les 5 000 derniers. Jamais
 * de texte libre, jamais la sortie d'une commande. Aucun paramètre : l'audit
 * n'en garde pas.
 */
final class ListeDesTachesController extends DiagnosticController
{
    public function __invoke(Request $requete): Response
    {
        $maintenant = now();

        return $this->servir($requete, static function () use ($maintenant): EnveloppeRendue {
            $donnees = ['taches_planifiees' => new ProjectionDesTachesPlanifiees()->liste(FenetreDeDiagnostic::depuis(null, $maintenant))];

            return new EnveloppeDeDiagnostic()(
                $donnees,
                $maintenant,
                tronque: EnveloppeDeDiagnostic::signale($donnees, 'tronque'),
                echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'),
            );
        });
    }
}
