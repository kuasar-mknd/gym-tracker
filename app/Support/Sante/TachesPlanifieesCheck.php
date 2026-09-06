<?php

declare(strict_types=1);

namespace App\Support\Sante;

use App\Models\TachePlanifiee;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Ce que Sentry surveillait par ses moniteurs de tâches, lu ici dans le
 * moniteur local : une tâche échouée met la santé au rouge, une tâche en
 * retard à l'orange.
 */
final class TachesPlanifieesCheck extends Check
{
    #[\Override]
    public function getName(): string
    {
        return 'TachesPlanifiees';
    }

    public function run(): Result
    {
        $taches = TachePlanifiee::query()->orderBy('name')->get();

        /** @var list<string> $echouees */
        $echouees = $taches->filter(fn (TachePlanifiee $tache): bool => $tache->etat() === TachePlanifiee::ECHOUEE)->pluck('name')->values()->all();
        /** @var list<string> $enRetard */
        $enRetard = $taches->filter(fn (TachePlanifiee $tache): bool => $tache->etat() === TachePlanifiee::EN_RETARD)->pluck('name')->values()->all();

        $result = Result::make()
            ->meta(['suivies' => $taches->count(), 'echouees' => $echouees, 'en_retard' => $enRetard])
            ->shortSummary(sprintf('%d suivies, %d échouées, %d en retard', $taches->count(), count($echouees), count($enRetard)));

        if ($echouees !== []) {
            return $result->failed('Échouée : '.implode(', ', $echouees));
        }

        if ($enRetard !== []) {
            return $result->warning('En retard : '.implode(', ', $enRetard));
        }

        return $result->ok();
    }
}
