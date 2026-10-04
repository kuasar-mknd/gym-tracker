<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Le moniteur local des taches est ce qui signale qu'une chose ne s'est PAS
 * produite : une tache qui cesse de tourner ne leve aucune erreur, elle se
 * tait, et le silence ressemble au calme (#1443). Toute tache est suivie sauf
 * mention `doNotMonitor()`, et la sante met une tache en retard ou echouee au
 * rouge.
 */
\Illuminate\Support\Facades\Schedule::command('app:remind-training')
    ->dailyAt('18:00');

/*
 * Le controle de coherence tourne sur les donnees reelles, pas sur un scenario.
 *
 * Les quatre defauts trouves le 18/08 avaient la meme forme — une valeur derivee
 * qui ne correspondait plus a sa source — et aucun n'avait ete vu en lisant le
 * code. Tous auraient ete visibles ici des la nuit suivante.
 *
 * Sans `--repair` : il signale, il ne repare pas. Reparer masquerait la cause, et
 * c'est la cause qui interesse. La sortie en erreur remonte au moniteur, qui sait
 * aussi dire que le controle n'a PAS tourne.
 */
\Illuminate\Support\Facades\Schedule::command('app:verify-data-coherence')
    ->dailyAt('04:30');

// Le journal d'activité ne garde plus que l'audit des comptes (User, Admin) ;
// sans purge, la table ne faisait que grossir (#1670). `--force` : la commande
// demande confirmation en production et, lancée par le planificateur, sans
// personne pour répondre, s'annulait à chaque passage — la table n'a jamais
// été purgée.
\Illuminate\Support\Facades\Schedule::command('activitylog:clean', ['--days' => 180, '--force' => true])
    ->dailyAt('03:30');

/*
 * Les trois tâches de sauvegarde touchent le partage des sauvegardes, qui peut
 * cesser de répondre sans rendre d'erreur : au premier plan, un seul accès qui
 * attendait figeait le passage du planificateur, et les tâches suivantes de la
 * minute avec lui (#1929). Chacune tourne donc dans son propre processus ; le
 * moniteur en reçoit la fin par `schedule:finish`, que le planificateur ajoute
 * à la commande. Le verrou empêche d'empiler un passage sur le précédent resté
 * pris. Il dure un jour et une heure : à vingt-quatre heures, son défaut, il
 * expirerait à la seconde où le passage du lendemain le cherche ; au-delà de
 * deux jours, un verrou laissé par un planificateur arrêté en plein passage
 * coûterait plus d'une nuit de sauvegarde.
 */
\Illuminate\Support\Facades\Schedule::runInBackground()
    ->withoutOverlapping(expiresAt: 25 * 60)
    ->group(function (): void {
        \Illuminate\Support\Facades\Schedule::command('backup:clean', ['--disable-notifications' => true])
            ->dailyAt('02:00');

        \Illuminate\Support\Facades\Schedule::command('backup:run', ['--only-db' => true, '--disable-notifications' => true])
            ->dailyAt('02:30');

        \Illuminate\Support\Facades\Schedule::command('backup:monitor', ['--disable-notifications' => true])
            ->dailyAt('08:00');
    });

/*
 * La santé de l'application, lue dans le panneau (« Système › Santé »).
 *
 * Les deux battements sont ce que `ScheduleCheck` et `QueueCheck` attendent :
 * s'ils cessent, c'est le contrôle lui-même qui le dit, pas un moniteur
 * extérieur. Le contrôle, lui, est surveillé comme toute autre tâche. Le
 * battement du planificateur reste la dernière tâche du fichier, comme le
 * demande le paquet.
 */
/*
 * Le moniteur des tâches (« Système › Tâches planifiées ») écrit trois lignes
 * par exécution. Les trois tâches de santé tournent 1 728 fois par jour :
 * hors moniteur, sinon la base y passerait ses nuits (#1668). Leur absence se
 * lit déjà sur la page de santé.
 */
\Illuminate\Support\Facades\Schedule::command(\Spatie\Health\Commands\RunHealthChecksCommand::class)
    ->everyFiveMinutes()
    ->doNotMonitor();

\Illuminate\Support\Facades\Schedule::command('model:prune', ['--model' => [\Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem::class]])
    ->dailyAt('03:15');

\Illuminate\Support\Facades\Schedule::command('model:prune', ['--model' => [\App\Models\ErreurNavigateur::class]])
    ->dailyAt('03:45');

\Illuminate\Support\Facades\Schedule::command(\Spatie\Health\Commands\DispatchQueueCheckJobsCommand::class)
    ->everyMinute()
    ->doNotMonitor();

\Illuminate\Support\Facades\Schedule::command(\Spatie\Health\Commands\ScheduleCheckHeartbeatCommand::class)
    ->everyMinute()
    ->doNotMonitor();
