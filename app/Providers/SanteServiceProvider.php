<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Sante\TachesPlanifieesCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * Ce que la page « Santé » du panneau montre et ce que `health:check` vérifie
 * toutes les cinq minutes en production : le pendant, à l'exécution, de ce
 * que Doctor regarde dans la CI.
 */
final class SanteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            // Le battement passe par la file « default », celle qu'Horizon sert.
            QueueCheck::new()->onQueue('default')->failWhenHealthJobTakesLongerThanMinutes(5),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            TachesPlanifieesCheck::new(),
            HorizonCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70)->failWhenUsedSpaceIsAbovePercentage(90),
            // La sauvegarde nocturne tombe à 02 h 30 : vingt-six heures laissent une
            // nuit de marge. La date est prise au démarrage du processus, ce qui
            // convient à `health:check`, lancé à neuf par le planificateur. Par
            // chemin plutôt que par `onDisk()`, qui résoudrait le disque à chaque
            // démarrage et figerait sa racine avant qu'un test ne la déplace.
            BackupsCheck::new()
                ->locatedAt(config()->string('filesystems.disks.sauvegardes.root').'/'.config()->string('backup.backup.name').'/*.zip')
                ->numberOfBackups(min: 1)
                ->youngestBackShouldHaveBeenMadeBefore(now()->subHours(26)),
            DebugModeCheck::new(),
            EnvironmentCheck::new(),
            OptimizedAppCheck::new(),
        ]);
    }
}
