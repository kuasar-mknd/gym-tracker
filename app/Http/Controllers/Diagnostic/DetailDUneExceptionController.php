<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/exceptions/{id}`, sous la portée `Exceptions` (#2032) :
 * la structure d'une exception, sa pile sans arguments. Le message et le
 * chemin brut, sous `non_fiable`, avec `TexteBrut` seulement : sans elle la
 * clé n'existe pas.
 */
final class DetailDUneExceptionController extends DiagnosticController
{
    public function __invoke(Request $request, string $identifiant): Response
    {
        $maintenant = now();
        $this->parametres($request, ['identifiant' => (int) $identifiant]);
        $texteBrut = in_array(PorteeDeDiagnostic::TexteBrut->value, $this->jeton($request)->portees, true);

        return $this->servir($request, function () use ($identifiant, $maintenant, $texteBrut): Response|EnveloppeRendue {
            $exception = new ProjectionDesExceptions()->detail((int) $identifiant, FenetreDeDiagnostic::depuis(null, $maintenant), $texteBrut);

            return $exception === null
                ? $this->introuvable()
                : new EnveloppeDeDiagnostic()(['exception' => $exception], $maintenant);
        });
    }
}
