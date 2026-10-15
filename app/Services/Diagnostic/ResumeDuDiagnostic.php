<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDeLaSante;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use App\Services\Diagnostic\Projections\ProjectionDesVersions;

/**
 * Le résumé du diagnostic pour les portées données (#2032) : ce que la page
 * « Aperçu du diagnostic » et `php artisan diagnostic:resume` montrent, et
 * ce que l'agent lirait. Les listes seulement, donc de la structure seule :
 * aucun texte libre, quelles que soient les portées, `TexteBrut` compris.
 */
final class ResumeDuDiagnostic
{
    /**
     * @param  list<PorteeDeDiagnostic>  $portees
     */
    public function __invoke(array $portees, FenetreDeDiagnostic $fenetre): EnveloppeRendue
    {
        $donnees = [
            'application' => new ProjectionDesVersions()->application(),
            'fenetre' => ['depuis' => ValeurLue::instant($fenetre->depuis), 'jusqua' => ValeurLue::instant($fenetre->jusqua)],
            'portees' => array_values(array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, $portees)),
        ];

        foreach (PorteeDeDiagnostic::SOURCES as $portee) {
            if (in_array($portee, $portees, true)) {
                $donnees = [...$donnees, ...$this->section($portee, $fenetre)];
            }
        }

        return new EnveloppeDeDiagnostic()(
            $donnees,
            $fenetre->jusqua,
            tronque: EnveloppeDeDiagnostic::signale($donnees, 'tronque'),
            echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function section(PorteeDeDiagnostic $portee, FenetreDeDiagnostic $fenetre): array
    {
        return match ($portee) {
            PorteeDeDiagnostic::Sante => [
                'sante' => new ProjectionDeLaSante()->liste($fenetre),
                'taches_planifiees' => new ProjectionDesTachesPlanifiees()->liste($fenetre),
                'versions' => new ProjectionDesVersions()->liste(),
            ],
            PorteeDeDiagnostic::Exceptions => ['exceptions' => new ProjectionDesExceptions()->liste($fenetre)],
            PorteeDeDiagnostic::Files => ['files' => new ProjectionDesFiles()->liste($fenetre)],
            PorteeDeDiagnostic::ErreursNavigateur => ['erreurs_navigateur' => new ProjectionDesErreursNavigateur()->liste($fenetre)],
            PorteeDeDiagnostic::TexteBrut => [],
        };
    }
}
