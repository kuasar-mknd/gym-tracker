<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDeLaSante;
use App\Services\Diagnostic\Projections\ProjectionDesVersions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/sante`, sous la portée `Sante` (#2032) : les contrôles
 * (nom, libellé, statut) et ce que chaque conteneur annonce de sa version.
 * Avec `TexteBrut`, le résumé et le message de chaque contrôle, sous
 * `non_fiable`. Le dernier passage se lit, il ne se relance jamais.
 */
final class SanteDuDiagnosticController extends DiagnosticController
{
    public function __invoke(Request $request): Response
    {
        $jeton = $this->jeton($request);
        $maintenant = now();
        $texteBrut = in_array(PorteeDeDiagnostic::TexteBrut->value, $jeton->portees, true);

        return $this->servir($request, static fn (): EnveloppeRendue => new EnveloppeDeDiagnostic()([
            'sante' => new ProjectionDeLaSante()->detail(FenetreDeDiagnostic::depuis(null, $maintenant), $texteBrut),
            'versions' => new ProjectionDesVersions()->liste(),
        ], $maintenant));
    }
}
