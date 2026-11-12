<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Services\Diagnostic\EtatDuDiagnostic;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/etat`, ouvert à tout jeton (#2032) : l'application, le
 * jeton et, selon ses portées, le plus court résumé de chaque source.
 */
final class EtatController extends DiagnosticController
{
    public function __invoke(Request $request, EtatDuDiagnostic $etat): Response
    {
        return $this->repondre($request, $etat($this->jeton($request), now()));
    }
}
