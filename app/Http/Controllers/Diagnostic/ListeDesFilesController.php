<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/files`, sous la portée `Files` (#2032) : l'état d'Horizon
 * et de chaque file (jobs en attente, attente estimée, âge du plus ancien), les jobs échoués des
 * trente derniers jours comptés par file et groupés par classe de job et
 * d'exception (au plus 50 groupes, lus sur les 5 000 derniers échecs), et les
 * cinq derniers. La charge utile d'un job n'est jamais envoyée ; le message d'une exception,
 * sous `non_fiable`, avec `TexteBrut` seulement : sans elle la clé n'existe pas. Aucun paramètre : l'audit n'en garde pas.
 */
final class ListeDesFilesController extends DiagnosticController
{
    public function __invoke(Request $requete): Response
    {
        $maintenant = now();
        $texteBrut = in_array(PorteeDeDiagnostic::TexteBrut->value, $this->jeton($requete)->portees, true);

        return $this->servir($requete, static function () use ($maintenant, $texteBrut): EnveloppeRendue {
            $fenetre = FenetreDeDiagnostic::depuis(null, $maintenant);
            $projection = new ProjectionDesFiles();
            $donnees = ['files' => [...$projection->liste($fenetre), 'derniers_echecs' => $projection->derniers($fenetre, $texteBrut)]];

            return new EnveloppeDeDiagnostic()(
                $donnees,
                $maintenant,
                tronque: EnveloppeDeDiagnostic::signale($donnees, 'tronque'),
                echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'),
            );
        });
    }
}
