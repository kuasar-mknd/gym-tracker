<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/erreurs-navigateur/{empreinte}`, sous la portée
 * `ErreursNavigateur` (#2032) : un groupe des vingt-quatre dernières heures, comme la liste, désigné
 * par son empreinte (32 chiffres hexadécimaux, contrainte à la route). Le
 * message, la page (son chemin sous un hôte masqué) et les vingt premières lignes de la pile
 * de sa dernière occurrence, sous `non_fiable`, avec `TexteBrut` seulement :
 * sans elle la clé n'existe pas. Un groupe que la base ne connaît pas : 404.
 */
final class DetailDUnGroupeDErreursNavigateurController extends DiagnosticController
{
    public function __invoke(Request $request, string $empreinte): Response
    {
        $maintenant = now();
        $this->parametres($request, ['empreinte' => $empreinte]);
        $texteBrut = in_array(PorteeDeDiagnostic::TexteBrut->value, $this->jeton($request)->portees, true);

        return $this->servir($request, function () use ($empreinte, $maintenant, $texteBrut): Response|EnveloppeRendue {
            $donnees = ['erreur_navigateur' => new ProjectionDesErreursNavigateur()->detail($empreinte, FenetreDeDiagnostic::depuis(null, $maintenant), $texteBrut)];

            return $donnees['erreur_navigateur'] === null
                ? $this->introuvable()
                : new EnveloppeDeDiagnostic()($donnees, $maintenant, echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'));
        });
    }
}
