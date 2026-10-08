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

/*
 * Un drapeau sans valeur s'écrit en liste, jamais `'--force' => true` : le
 * planificateur compile le tableau en ligne de commande, `true` y devient
 * `'1'`, et la console refuse `--force='1'` avant de rien lancer (« option
 * does not accept a value »). Passées par `Artisan::call()`, qui accepte
 * `true`, les mêmes commandes réussissaient : cette purge et les trois
 * sauvegardes plus bas sortaient pourtant en erreur à chaque passage (#2020).
 * `ChaqueTachePlanifieeEstAccepteeParSaCommandeTest` lit chaque ligne compilée
 * contre la définition de sa commande.
 *
 * Le journal d'activité ne garde plus que l'audit des comptes (User, Admin) ;
 * sans purge, la table ne faisait que grossir (#1670). `--force` : la commande
 * demande confirmation en production et, lancée par le planificateur, sans
 * personne pour répondre, s'annulait à chaque passage.
 */
\Illuminate\Support\Facades\Schedule::command('activitylog:clean', ['--days' => 180, '--force'])
    ->dailyAt('03:30');

/*
 * Un jeton de réinitialisation du mot de passe expire au bout de 60 minutes
 * (`config/auth.php`), mais sa ligne restait en base, sous l'adresse de
 * courriel, que le compte existe encore ou non (#1938). La commande ne demande
 * aucune confirmation en production. 01:45 : avant la sauvegarde de 02:30, et
 * hors de l'heure que les changements d'heure sautent ou répètent.
 */
\Illuminate\Support\Facades\Schedule::command('auth:clear-resets')
    ->dailyAt('01:45');

/*
 * Les trois tâches de sauvegarde touchent le partage des sauvegardes, qui peut
 * cesser de répondre sans rendre d'erreur : au premier plan, un seul accès qui
 * attendait figeait le passage du planificateur, et les tâches suivantes de la
 * minute avec lui (#1929). Chacune tourne donc dans son propre processus ; le
 * moniteur en reçoit la fin par `schedule:finish`, que le planificateur ajoute
 * à la commande. Le verrou empêche d'empiler un passage sur le précédent resté
 * pris. Il dure un jour et une heure : à vingt-quatre heures, son défaut, il
 * expirerait à la seconde où le passage du lendemain le cherche ; au-delà de
 * deux jours, un verrou que rien ne rend coûterait plus d'une nuit de
 * sauvegarde. Seule la fin de la tâche le rend : celui qu'emporte un
 * planificateur arrêté en plein passage est rendu à son démarrage, par
 * `entrypoint.sh`, sans quoi il ferait sauter la nuit suivante.
 *
 * Aucune n'écrit de courriel : `backup:clean` et `backup:run` coupent les
 * leurs, et `backup:monitor`, qui n'a pas d'option pour cela, n'a aucun canal
 * pour ses deux avis dans `config/backup.php`. Son échec (archive de plus d'un
 * jour, ou plus de 2 000 Mo d'archives) passe par le moniteur des tâches, qui
 * met la santé au rouge et écrit à `HEALTH_TO_ADDRESS` ; « Backups », sur la
 * page de santé, voit déjà une archive de plus de vingt-six heures. Le
 * nettoyage de 02:00 tient les archives sous 1 500 Mo, pour que ce seuil de
 * place ne sonne pas chaque matin (`config/backup.php`).
 */
\Illuminate\Support\Facades\Schedule::runInBackground()
    ->withoutOverlapping(expiresAt: 25 * 60)
    ->group(function (): void {
        \Illuminate\Support\Facades\Schedule::command('backup:clean', ['--disable-notifications'])
            ->dailyAt('02:00');

        \Illuminate\Support\Facades\Schedule::command('backup:run', ['--only-db', '--disable-notifications'])
            ->dailyAt('02:30');

        \Illuminate\Support\Facades\Schedule::command('backup:monitor')
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
