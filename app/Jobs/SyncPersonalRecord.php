<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Set;
use App\Models\User;
use App\Services\PersonalRecordService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
 * renvoie rien.
 */
final class SyncPersonalRecord implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Set $set,
        public User $user
    ) {
    }

    public function uniqueId(): string
    {
        return "sync_pr_{$this->user->id}_{$this->set->id}";
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
