<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDeLaSante;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;

/**
 * Le détail que l'agent lirait du premier élément de chaque liste du résumé
 * (#2032) : la santé, la première tâche échouée, le premier groupe
 * d'exceptions, d'échecs de job et d'erreurs navigateur. C'est au détail, et
 * seulement sous `TexteBrut`, que le texte libre sort, expurgé, sous
 * `non_fiable` : la page de l'aperçu le montre tel quel. Un détail qui a
 * compté moins de lignes que sa source n'en a le dit (`echantillon`), et
 * l'enveloppe le remonte, comme celle du résumé.
 */
final class ApercuDesDetails
{
    /**
     * @param  list<PorteeDeDiagnostic>  $portees
     * @param  array<array-key, mixed>  $resume  les données du résumé, d'où viennent les premiers éléments
     */
    public function __invoke(array $portees, FenetreDeDiagnostic $fenetre, array $resume): EnveloppeRendue
    {
        $texteBrut = in_array(PorteeDeDiagnostic::TexteBrut, $portees, true);
        $details = [];

        foreach (PorteeDeDiagnostic::SOURCES as $portee) {
            if (in_array($portee, $portees, true)) {
                $details = [...$details, ...$this->detail($portee, $fenetre, $resume, $texteBrut)];
            }
        }

        return new EnveloppeDeDiagnostic()(
            $details,
            $fenetre->jusqua,
            tronque: EnveloppeDeDiagnostic::signale($details, 'tronque'),
            echantillon: EnveloppeDeDiagnostic::signale($details, 'echantillon'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $resume
     * @return array<string, mixed>
     */
    private function detail(PorteeDeDiagnostic $portee, FenetreDeDiagnostic $fenetre, array $resume, bool $texteBrut): array
    {
        return match ($portee) {
            PorteeDeDiagnostic::Sante => [
                'sante' => new ProjectionDeLaSante()->detail($fenetre, $texteBrut),
                'tache_planifiee' => new ProjectionDesTachesPlanifiees()->detail($this->premiereTacheEchouee($resume), $fenetre, $texteBrut),
            ],
            PorteeDeDiagnostic::Exceptions => [
                'exception' => new ProjectionDesExceptions()->detail(ValeurLue::entier(data_get($resume, 'exceptions.groupes.0.dernier_id')) ?? 0, $fenetre, $texteBrut),
            ],
            PorteeDeDiagnostic::Files => [
                'echec_de_job' => new ProjectionDesFiles()->detail(ValeurLue::texte(data_get($resume, 'files.echecs.0.dernier_uuid')), $fenetre, $texteBrut),
            ],
            PorteeDeDiagnostic::ErreursNavigateur => [
                'erreur_navigateur' => new ProjectionDesErreursNavigateur()->detail(ValeurLue::texte(data_get($resume, 'erreurs_navigateur.groupes.0.empreinte')), $fenetre, $texteBrut),
            ],
            PorteeDeDiagnostic::TexteBrut => [],
        };
    }

    /**
     * La première tâche échouée du résumé, sinon la première tâche : c'est
     * l'échec que le détail montre.
     *
     * @param  array<array-key, mixed>  $resume
     */
    private function premiereTacheEchouee(array $resume): int
    {
        $taches = collect((array) data_get($resume, 'taches_planifiees.taches', []));
        $tache = $taches->first(static fn (mixed $une): bool => data_get($une, 'etat') === 'echouee') ?? $taches->first();

        return ValeurLue::entier(data_get($tache, 'id')) ?? 0;
    }
}
