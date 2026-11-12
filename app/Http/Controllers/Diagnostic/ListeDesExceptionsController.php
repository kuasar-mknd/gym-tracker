<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Http\Requests\Diagnostic\ListeDesExceptionsRequest;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/exceptions?depuis=`, sous la portée `Exceptions`
 * (#2032) : les groupes (type, fichier, ligne) de la fenêtre, au plus 50,
 * lus sur les 5 000 dernières lignes. Jamais de texte libre.
 */
final class ListeDesExceptionsController extends DiagnosticController
{
    public function __invoke(ListeDesExceptionsRequest $requete): Response
    {
        $maintenant = now();
        $fenetre = $requete->fenetre($maintenant);
        $this->parametres($requete, ['depuis' => $fenetre->depuis->toIso8601String()]);

        return $this->servir($requete, static function () use ($fenetre, $maintenant): EnveloppeRendue {
            $donnees = ['exceptions' => new ProjectionDesExceptions()->liste($fenetre)];

            return new EnveloppeDeDiagnostic()(
                $donnees,
                $maintenant,
                tronque: EnveloppeDeDiagnostic::signale($donnees, 'tronque'),
                echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'),
            );
        });
    }
}
