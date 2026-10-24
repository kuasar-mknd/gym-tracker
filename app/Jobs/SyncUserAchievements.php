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
 * Débloque les succès qu'un compte vient d'atteindre, après une séance ou une
 * série enregistrée. Jamais perdu, un seul à la fois par compte :
 * `UnRecalculParCompteALaFois` dit comment (#2105).
 */
#[CountCrashesAsExceptions]
#[MaxExceptions(1)]
#[Tries(self::ESSAIS_PERMIS)]
final class SyncUserAchievements implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use UnRecalculParCompteALaFois;

    public function __construct(public \App\Models\User $user)
    {
    }

    public function handle(\App\Services\AchievementService $achievementService): void
    {
        $achievementService->syncAchievements($this->user);
    }
}
