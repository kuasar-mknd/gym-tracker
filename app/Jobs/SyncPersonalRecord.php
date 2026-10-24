<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Set;
use App\Models\User;
use App\Services\PersonalRecordService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Confronte une série validée aux records de son exercice.
 *
 * Passé par un worker, il renvoie ensuite le recalcul des succès et celui des
 * objectifs du compte, qui lisent les records (#2105). `Set::saved` les envoie
 * dès la requête, avant que ce travail n'écrive : un autre worker pouvait les
 * faire tourner sur l'ancien record, ou un recalcul déjà en file écarter leur
 * envoi puis tourner avant ce travail, et rien ne les relançait ensuite.
 * L'objectif restait « non atteint » alors que le record l'atteignait. Un
 * recalcul qui attend encore lira le record écrit, et l'envoi est écarté ; un
 * recalcul déjà commencé laisse passer l'envoi, qui tourne après lui
 * (`UnRecalculParCompteALaFois`). Exécuté dans la requête (`dispatchSync()`,
 * les tests), il écrit le record avant les envois de `Set::saved`, et ne
 * renvoie rien. Appelé directement (`handle()`), il n'envoie rien non plus,
 * et aucun envoi de `Set::saved` ne le suit : l'appelant envoie lui-même les
 * deux recalculs après lui.
 *
 * Unique jusqu'au traitement, borné, et seul à tourner pour sa série, comme
 * les deux recalculs (#2130) : `UnRecalculParCompteALaFois` dit comment, sur
 * la clé de la série et de son compte. `ShouldBeUnique`, sans échéance, gardait
 * le verrou jusqu'à la fin de `handle()` : une série corrigée pendant que sa
 * synchronisation comparait les records à la série déjà relue ne mettait rien
 * en file, et le record restait celui de la valeur d'avant ; un travail vidé de
 * la file bloquait pour toujours les records de la série. La correction met
 * désormais une nouvelle synchronisation en file, qui relira la série ; tant
 * qu'une synchronisation attend sans avoir commencé, elle relira la série
 * corrigée (`PersonalRecordService` la relit sous le verrou des records), et
 * l'envoi est écarté. Deux synchronisations de la même série ne tournent
 * jamais ensemble : la seconde revient en file sans tenir de worker, au lieu
 * d'attendre la première sous le verrou des records de l'exercice. Les séries
 * d'un même exercice s'écrivent déjà l'une après l'autre sous ce verrou, et
 * celles des autres exercices n'ont rien à attendre : la sérialisation
 * s'arrête à la série.
 */
#[CountCrashesAsExceptions]
#[MaxExceptions(1)]
#[Tries(self::ESSAIS_PERMIS)]
final class SyncPersonalRecord implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use UnRecalculParCompteALaFois;

    public function __construct(
        public Set $set,
        public User $user
    ) {
    }

    /**
     * La clé du travail, à la place du compte seul du trait : la série et son
     * compte. Elle n'a plus la forme d'avant #2130 (`sync_pr_{compte}_{série}`) :
     * un verrou pris sous cette forme n'avait pas d'échéance, et celui d'un
     * travail vidé de la file avant le correctif retiendrait sinon la série
     * pour toujours.
     */
    public function uniqueId(): string
    {
        return "{$this->user->id}:{$this->set->id}";
    }

    public function handle(PersonalRecordService $personalRecordService): void
    {
        $personalRecordService->synchroniserLesRecordsDeLaSerie($this->set, $this->user);

        if ($this->aTourneDansUnWorker()) {
            SyncUserAchievements::dispatch($this->user);
            SyncUserGoals::dispatch($this->user);
        }
    }

    /**
     * Le travail est passé par une file que lit un worker, et non par la file
     * synchrone ni par un appel direct à `handle()`.
     */
    private function aTourneDansUnWorker(): bool
    {
        return $this->job instanceof Job && ! $this->job instanceof SyncJob;
    }
}
