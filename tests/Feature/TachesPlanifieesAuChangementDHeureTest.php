<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/*
 * Aucune tâche à heure fixe ne part deux ou trois fois la nuit du passage à
 * l'heure d'hiver (#2389), et aucune ne saute celle du printemps. Le fuseau
 * répète 02:00–03:00 le 25 octobre 2026 : la tâche réglée à 03:xx s'y
 * présentait trois fois (02:xx CEST, 02:xx CET, 03:xx CET), hors de l'heure
 * répétée comme dedans (#2332 les avait déjà déplacées de 02:xx vers 03:xx,
 * ce qui ne réglait rien). Le planificateur est rejoué minute par minute sur
 * le jour de Paris, comme il tourne, avec la même `isDue` ; les tâches à
 * fréquence (`everyMinute`, `everyFiveMinutes`) en sont exclues, elles
 * repartent de toute façon.
 */

/**
 * Les tâches à heure fixe : celles dont la minute du cron n'est ni un joker,
 * ni un pas, ni une liste.
 *
 * @return Collection<int, Event>
 */
function tachesPlanifieesAHeureFixe(): Collection
{
    return collect(app(Schedule::class)->events())
        ->reject(static fn (Event $evenement): bool => preg_match('/[*\/,]/', explode(' ', $evenement->expression)[0]) === 1)
        ->values();
}

/**
 * Les départs de chaque tâche à heure fixe sur le jour de Paris donné, en
 * heure de Paris avec son abréviation et son décalage (CEST +02:00, CET +01:00).
 *
 * @return array<string, list<string>>
 */
function tachesPlanifieesDepartsDuJour(string $jour): array
{
    $debut = Carbon::parse($jour.' 00:00:00', 'Europe/Paris')->utc();
    $fin = $debut->copy()->setTimezone('Europe/Paris')->addDay()->utc();
    $evenements = tachesPlanifieesAHeureFixe();

    $departs = [];

    try {
        for ($instant = $debut->copy(); $instant->lessThan($fin); $instant->addMinute()) {
            Carbon::setTestNow($instant->copy());

            foreach ($evenements as $evenement) {
                if ($evenement->isDue(app())) {
                    $departs[(string) $evenement->command][] = $instant->copy()->setTimezone('Europe/Paris')->format('H:i T P');
                }
            }
        }
    } finally {
        Carbon::setTestNow();
    }

    return $departs;
}

it('lance chaque tâche à heure fixe une fois, et aucune ne saute, les nuits de changement d’heure', function (string $jour): void {
    $departs = tachesPlanifieesDepartsDuJour($jour);

    expect(tachesPlanifieesAHeureFixe()->count())->toBeGreaterThanOrEqual(12)
        ->and($departs)->toHaveCount(tachesPlanifieesAHeureFixe()->count());

    foreach ($departs as $commande => $heures) {
        expect($heures)->toHaveCount(1, $commande.' part à '.implode(', ', $heures));
    }
})->with([
    'heure d’hiver' => ['2026-10-25'],
    'heure d’été' => ['2026-03-29'],
]);

it('range les purges avant les sauvegardes, sans collision, hors des heures que le changement d’heure touche', function (): void {
    $minutes = collect(tachesPlanifieesDepartsDuJour('2026-10-24'))
        ->map(static fn (array $departs): int => (int) substr($departs[0], 0, 2) * 60 + (int) substr($departs[0], 3, 2));

    $premiereSauvegarde = $minutes
        ->filter(static fn (int $minute, string $commande): bool => str_contains($commande, 'backup:clean') || str_contains($commande, 'backup:run'))
        ->min();
    assert(is_int($premiereSauvegarde), 'Le planning ne lance plus de sauvegarde.');

    expect($minutes->unique()->count())->toBe($minutes->count(), 'Deux tâches partent à la même minute.');

    foreach ($minutes as $commande => $minute) {
        expect($minute < 2 * 60 || $minute >= 4 * 60)->toBeTrue($commande.' part entre 02:00 et 04:00.');

        if (str_contains($commande, 'prune') || str_contains($commande, 'activitylog:clean') || str_contains($commande, 'auth:clear-resets')) {
            expect($minute)->toBeLessThan($premiereSauvegarde, $commande.' part après la première sauvegarde.');
        }
    }
});
