<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * `sentryMonitor()` est le seul mecanisme du depot capable de signaler qu'une
 * chose ne s'est PAS produite.
 *
 * Une tache planifiee qui cesse de tourner ne leve aucune erreur : elle se tait,
 * et le silence ressemble au calme. C'est ainsi que l'absence de service
 * `scheduler` est passee inapercue jusqu'a #1443 — la commande n'avait jamais
 * tourne. Le moniteur attend le battement et alerte quand il manque.
 */
\Illuminate\Support\Facades\Schedule::command('app:remind-training')
    ->dailyAt('18:00')
    ->sentryMonitor();

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
    ->dailyAt('04:30')
    ->sentryMonitor();

// Le journal d'activité ne garde plus que l'audit des comptes (User, Admin) ;
// sans purge, la table ne faisait que grossir (#1670).
\Illuminate\Support\Facades\Schedule::command('activitylog:clean', ['--days' => 180])
    ->dailyAt('03:30')
    ->sentryMonitor();

\Illuminate\Support\Facades\Schedule::command('backup:clean', ['--disable-notifications' => true])
    ->dailyAt('02:00')
    ->sentryMonitor();

\Illuminate\Support\Facades\Schedule::command('backup:run', ['--only-db' => true, '--disable-notifications' => true])
    ->dailyAt('02:30')
    ->sentryMonitor();

\Illuminate\Support\Facades\Schedule::command('backup:monitor', ['--disable-notifications' => true])
    ->dailyAt('08:00')
    ->sentryMonitor();
