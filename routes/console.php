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
    ->daily()
    ->sentryMonitor();
