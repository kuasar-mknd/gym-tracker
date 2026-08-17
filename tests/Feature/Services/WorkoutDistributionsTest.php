<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\WorkoutStatsService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Le corps entier de `getWorkoutDistributions` pouvait etre remplace par
 * `return []` sans qu'un seul test ne tombe.
 *
 * Le mecanisme exact : `DashboardTest.php:44` assure que la prop
 * `workoutDistributions` est ABSENTE de la reponse initiale — c'est une prop
 * differee — et `PageRenderingTest.php:54` charge les props differees avec une
 * closure vide. La methode s'execute donc bien, ce qui explique que des mutants
 * y soient generes, mais personne ne lit ce qu'elle rend.
 *
 * Consequence d'un retour vide : `Dashboard.vue:73` et `:76` lisent
 * `analyticalStats?.workoutDistributions?.duration` et `.time_of_day`, obtiennent
 * `undefined`, et les DEUX graphiques du tableau de bord disparaissent — sans
 * erreur, sans trace.
 *
 * Ce fichier ferme la grappe : le cardinal et l'ordre des seaux, les comptes
 * exacts, et le seau vide qui doit rester present a zero. Les mutations sur les
 * valeurs initiales des seaux (0 devenant -1 ou 1) et sur leur suppression
 * (`RemoveArrayItem`) tombent toutes sur ces assertions (#1446).
 */

/**
 * Les seaux de duree, dans l'ordre ou le service les declare.
 *
 * @return list<string>
 */
function seauxDeDuree(): array
{
    return [__('< 30 min'), __('30-60 min'), __('60-90 min'), __('90+ min')];
}

/**
 * Une seance d'une duree donnee, demarree a une heure choisie.
 */
function seanceDe(User $user, int $minutes, int $heure = 10): Workout
{
    $debut = now()->subDays(2)->setTime($heure, 0);

    return Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $debut,
        'ended_at' => $debut->copy()->addMinutes($minutes),
    ]);
}

it('renvoie les quatre tranches de durée, dans l’ordre et avec leur compte', function (): void {
    $user = User::factory()->create();

    seanceDe($user, 20);
    seanceDe($user, 45);
    seanceDe($user, 75);
    seanceDe($user, 120);

    $distributions = app(WorkoutStatsService::class)->getWorkoutDistributions($user);

    expect($distributions)->toHaveKeys(['duration', 'time_of_day']);

    $duree = $distributions['duration'];

    // Le cardinal et l'ordre, pas seulement les comptes : c'est ce qui attrape
    // la suppression d'un seau.
    expect($duree)->toHaveCount(4)
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): string => $stat->label, $duree))->toBe(seauxDeDuree())
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $duree))->toBe([1, 1, 1, 1]);
});

it('garde une tranche vide présente, à zéro', function (): void {
    $user = User::factory()->create();

    // Une seule seance, dans la tranche la plus courte : les trois autres
    // doivent rester la, a zero. Un seau initialise a -1 ou a 1, ou supprime,
    // se voit ici.
    seanceDe($user, 20);

    $duree = app(WorkoutStatsService::class)->getWorkoutDistributions($user)['duration'];

    expect($duree)->toHaveCount(4)
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $duree))->toBe([1, 0, 0, 0]);
});

it('range les séances par moment de la journée', function (): void {
    $user = User::factory()->create();

    seanceDe($user, 30, 8);   // matin
    seanceDe($user, 30, 14);  // apres-midi
    seanceDe($user, 30, 19);  // soir
    seanceDe($user, 30, 23);  // nuit

    $moments = app(WorkoutStatsService::class)->getWorkoutDistributions($user)['time_of_day'];

    expect($moments)->toHaveCount(4)
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): string => $stat->label, $moments))->toBe([
            __('Morning (06h-12h)'),
            __('Afternoon (12h-17h)'),
            __('Evening (17h-22h)'),
            __('Night (22h-06h)'),
        ])
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $moments))->toBe([1, 1, 1, 1]);
});

/**
 * Les bornes, la ou deux implementations raisonnables divergent : 30 minutes
 * juste appartient a la tranche suivante, et minuit a la nuit.
 */
it('place les durées et les heures limites du bon côté', function (): void {
    $user = User::factory()->create();

    seanceDe($user, 30, 6);    // 30 min pile -> '30-60 min' ; 06h -> matin
    seanceDe($user, 90, 0);    // 90 min pile -> '90+ min'   ; 00h -> nuit

    $distributions = app(WorkoutStatsService::class)->getWorkoutDistributions($user);

    expect(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $distributions['duration']))->toBe([0, 1, 0, 1])
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $distributions['time_of_day']))->toBe([1, 0, 0, 1]);
});
