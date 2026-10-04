<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Sante\AnnonceDeVersion;
use App\Support\Sante\DossierDesSauvegardesCheck;
use App\Support\Sante\ReglagesDeLaBaseCheck;
use App\Support\Sante\TachesPlanifieesCheck;
use App\Support\Sante\VersionsDesConteneursCheck;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Foundation\Events\DiagnosingHealth as SondageDeSante;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStarting as DemarrageDUnTravailleurDeFile;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\WorkerStarting as DemarrageDUnTravailleurOctane;
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
use Spatie\Health\Jobs\HealthQueueJob;

/**
 * Ce que la page « Santé » du panneau montre et ce que `health:check` vérifie
 * toutes les cinq minutes en production : le pendant, à l'exécution, de ce
 * que Doctor regarde dans la CI.
 */
final class SanteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $archives = config()->string('filesystems.disks.sauvegardes.root').'/'.config()->string('backup.backup.name');

        Health::checks([
            DatabaseCheck::new(),
            ReglagesDeLaBaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            // Le battement passe par la file « default », celle qu'Horizon sert.
            QueueCheck::new()->onQueue('default')->failWhenHealthJobTakesLongerThanMinutes(5),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            TachesPlanifieesCheck::new(),
            HorizonCheck::new(),
            VersionsDesConteneursCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70)->failWhenUsedSpaceIsAbovePercentage(90),
            // Le délai du script de démarrage : au-delà, le partage est tenu pour endormi.
            DossierDesSauvegardesCheck::new()->delai(DossierDesSauvegardesCheck::DELAI_EN_SECONDES),
            // La sauvegarde nocturne tombe à 02 h 30 : vingt-six heures laissent une
            // nuit de marge. La date est prise au démarrage du processus, ce qui
            // convient à `health:check`, lancé à neuf par le planificateur. Par
            // chemin plutôt que par `onDisk()`, qui résoudrait le disque à chaque
            // démarrage et figerait sa racine avant qu'un test ne la déplace.
            // Son `glob()` n'a pas de délai : sur un partage qui ne répond plus,
            // il figerait tout le passage, et le rouge de « Dossier des
            // sauvegardes » avec lui. Il ne tourne que si le dossier répond.
            BackupsCheck::new()
                ->locatedAt($archives.'/*.zip')
                ->numberOfBackups(min: 1)
                ->youngestBackShouldHaveBeenMadeBefore(now()->subHours(26))
                ->if(static fn (): bool => DossierDesSauvegardesCheck::repond($archives)),
            DebugModeCheck::new(),
            EnvironmentCheck::new(),
            OptimizedAppCheck::new(),
        ]);

        $this->annoncerLesVersions();
    }

    /**
     * Chaque conteneur annonce l'image qu'il exécute quand il démarre (#1813),
     * puis régulièrement, parce que le cache de production évince les clés
     * qu'il sert le moins (#1930) : app quand Octane démarre un travailleur
     * et à chaque sondage de `/up`, toutes les trente secondes ; worker quand
     * Horizon démarre un processus de file et à chaque battement de la file,
     * chaque minute ; scheduler à chaque tâche, donc au moins une fois par
     * minute avec les battements. Une annonce évincée revient ainsi en une
     * minute. Jamais à chaque requête, et dans le cache, jamais en base. Le
     * battement ne vaut annonce du worker que traité par un worker : la file
     * `sync` l'exécuterait dans le processus qui l'envoie, le scheduler.
     */
    private function annoncerLesVersions(): void
    {
        $conteneurs = [
            DemarrageDUnTravailleurOctane::class => 'app',
            SondageDeSante::class => 'app',
            DemarrageDUnTravailleurDeFile::class => 'worker',
            ScheduledTaskStarting::class => 'scheduler',
        ];

        foreach ($conteneurs as $evenement => $conteneur) {
            Event::listen($evenement, static function () use ($conteneur): void {
                AnnonceDeVersion::annoncer($conteneur);
            });
        }

        Event::listen(JobProcessed::class, static function (JobProcessed $traite): void {
            if (! $traite->job instanceof SyncJob && $traite->job->resolveQueuedJobClass() === HealthQueueJob::class) {
                AnnonceDeVersion::annoncer('worker');
            }
        });
    }
}
