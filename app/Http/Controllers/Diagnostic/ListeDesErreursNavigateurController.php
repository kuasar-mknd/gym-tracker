<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /diagnostic/v1/erreurs-navigateur`, sous la portée `ErreursNavigateur`
 * (#2032) : les erreurs du navigateur des vingt-quatre dernières heures,
 * groupées par empreinte (au plus 50 groupes, lus sur les 5 000 dernières
 * lignes des comptes vérifiés). Jamais de texte libre, jamais le compte, l'URL
 * ni la chaîne d'agent : des nombres, des dates et des familles de
 * navigateurs. Aucun paramètre : l'audit n'en garde pas.
 */
final class ListeDesErreursNavigateurController extends DiagnosticController
{
    public function __invoke(Request $requete): Response
    {
        $maintenant = now();

        return $this->servir($requete, static function () use ($maintenant): EnveloppeRendue {
            $donnees = ['erreurs_navigateur' => new ProjectionDesErreursNavigateur()->liste(FenetreDeDiagnostic::depuis(null, $maintenant))];

            return new EnveloppeDeDiagnostic()(
                $donnees,
                $maintenant,
                tronque: EnveloppeDeDiagnostic::signale($donnees, 'tronque'),
                echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'),
            );
        });
    }
}
