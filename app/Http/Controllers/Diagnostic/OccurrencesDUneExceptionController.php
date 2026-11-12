<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Http\Requests\Diagnostic\OccurrencesDUneExceptionRequest;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/exceptions/{id}/occurrences?avant=`, sous la portée
 * `Exceptions` (#2032) : vingt-cinq occurrences par page (identifiant, date,
 * méthode, route), `suivant` donnant le `avant` de la page d'après. La taille
 * de la page n'est jamais celle du client.
 */
final class OccurrencesDUneExceptionController extends DiagnosticController
{
    public function __invoke(OccurrencesDUneExceptionRequest $requete, string $identifiant): Response
    {
        $maintenant = now();
        $this->parametres($requete, ['identifiant' => (int) $identifiant, 'avant' => $requete->curseur()]);

        return $this->servir($requete, function () use ($requete, $identifiant, $maintenant): Response|EnveloppeRendue {
            $page = new ProjectionDesExceptions()->occurrences((int) $identifiant, $requete->curseur(), FenetreDeDiagnostic::jours(FenetreDeDiagnostic::JOURS_AU_PLUS, $maintenant));

            return $page === null
                ? $this->introuvable()
                : new EnveloppeDeDiagnostic()(['occurrences' => $page['occurrences']], $maintenant, echantillon: $page['echantillon'], suivant: $page['suivant']);
        });
    }
}
