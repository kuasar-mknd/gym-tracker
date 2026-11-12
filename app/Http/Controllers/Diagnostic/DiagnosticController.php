<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\JetonDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
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

    protected function repondre(Request $request, EnveloppeRendue $rendue): Response
    {
        $request->attributes->set(ExigeUnJetonDeDiagnostic::ELEMENTS, count($rendue->enveloppe['donnees']));

        return new Response($rendue->json(), 200, ['Content-Type' => 'application/json']);
    }
}
