<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;

/**
 * Recalcule les objectifs d'un compte après une écriture qui peut les faire
 * bouger (séance, série, mesure). Jamais perdu, un seul à la fois par compte :
 * `UnRecalculParCompteALaFois` dit comment (#2105).
 */
#[CountCrashesAsExceptions]
#[MaxExceptions(1)]
#[Tries(self::ESSAIS_PERMIS)]
final class SyncUserGoals implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use UnRecalculParCompteALaFois;

    public function __construct(public \App\Models\User $user)
    {
    }

    public function handle(\App\Services\GoalService $goalService): void
    {
        $goalService->syncGoals($this->user);
    }
}
