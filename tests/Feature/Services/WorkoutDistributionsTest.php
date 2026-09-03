<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\WorkoutStatsService;

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

/**
 * Chaque heure limite, des deux côtés.
 *
 * Le test voisin ne posait que 06h et 00h : les bornes 12h, 17h et 22h n'étaient
 * vérifiées d'aucun côté, et l'heure elle-même est lue par un `substr($date, 11, 2)`
 * dont aucun décalage n'était couvert. Vingt-six mutants survivaient là-dessus.
 *
 * Chaque borne est donnée deux fois — la dernière minute d'avant et l'heure pile —
 * parce qu'un seul des deux côtés laisserait passer le décalage d'une unité.
 */
it('range chaque heure limite du bon côté', function (int $heure, int $seau): void {
    $user = User::factory()->create();

    seanceDe($user, 10, $heure);

    $moments = app(WorkoutStatsService::class)->getWorkoutDistributions($user)['time_of_day'];

    $comptes = array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $moments);

    $attendu = [0, 0, 0, 0];
    $attendu[$seau] = 1;

    expect($comptes)->toBe($attendu);
})->with([
    '05h, encore la nuit' => [5, 3],
    '06h, le matin commence' => [6, 0],
    '11h, encore le matin' => [11, 0],
    '12h, l’après-midi commence' => [12, 1],
    '16h, encore l’après-midi' => [16, 1],
    '17h, le soir commence' => [17, 2],
    '21h, encore le soir' => [21, 2],
    '22h, la nuit commence' => [22, 3],
    '00h, toujours la nuit' => [0, 3],
]);

/**
 * Chaque durée limite, des deux côtés.
 *
 * Même raison : le test voisin ne posait que 30 et 90 minutes pile. Une minute de
 * moins et une minute de plus encadrent chaque seuil, sinon un `<` devenu `<=`
 * passe inaperçu.
 */
it('range chaque durée limite du bon côté', function (int $minutes, int $seau): void {
    $user = User::factory()->create();

    seanceDe($user, $minutes, 10);

    $durees = app(WorkoutStatsService::class)->getWorkoutDistributions($user)['duration'];

    $comptes = array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $durees);

    $attendu = [0, 0, 0, 0];
    $attendu[$seau] = 1;

    expect($comptes)->toBe($attendu);
})->with([
    '29 min' => [29, 0],
    '30 min pile' => [30, 1],
    '59 min' => [59, 1],
    '60 min pile' => [60, 2],
    '89 min' => [89, 2],
    '90 min pile' => [90, 3],
]);

/**
 * Une séance à cheval sur minuit se mesure quand même.
 *
 * Le calcul rapide compare les dix premiers caractères des deux dates ; quand
 * elles diffèrent, il retombe sur un calcul par horodatage. Aucun test ne
 * franchissait minuit, donc ce repli n'était jamais emprunté — et le mutant qui
 * inversait la comparaison de dates ne cassait rien.
 *
 * Sans le repli, le calcul rapide donnerait ici |0h30 - 23h30| = 1380 minutes au
 * lieu de 60, soit la tranche « 90+ » au lieu de « 60-90 ».
 */
it('mesure une séance qui franchit minuit', function (): void {
    $user = User::factory()->create();

    $debut = now()->subDays(2)->setTime(23, 30);

    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $debut,
        'ended_at' => $debut->copy()->addMinutes(60),
    ]);

    $durees = app(WorkoutStatsService::class)->getWorkoutDistributions($user)['duration'];

    expect(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $durees))
        ->toBe([0, 0, 1, 0]);
});

/**
 * Les deux graphiques de duree du tableau de bord doivent compter la meme chose.
 *
 * `getWorkoutDistributions` avait un « chemin rapide » qui lisait les heures et
 * les minutes a coups de `substr`, en sautant les SECONDES. Sa voisine
 * `getDurationHistory` tronque des minutes reelles. Une seance de 10:00:30 a
 * 10:30:00 valait donc trente minutes pour l'une et vingt-neuf pour l'autre —
 * et trente est exactement la frontiere entre deux seaux, si bien que la meme
 * seance changeait de tranche selon le graphique qui la regardait.
 *
 * La production ecrit `now()`, secondes comprises (`CreateWorkoutAction`), donc
 * une seance sur soixante tombe dans ce cas. Aucun test ne le voyait : toutes
 * les fixtures de ce fichier se posent a la seconde 00, ce qui laissait dix
 * mutants survivre sur la seule ligne du `substr`.
 */
it('compte la meme duree que la courbe voisine, secondes comprises', function (): void {
    $user = User::factory()->create();

    $debut = now()->subDays(2)->setTime(10, 0, 30);

    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $debut,
        'ended_at' => $debut->copy()->setTime(10, 30, 0),
    ]);

    $service = app(WorkoutStatsService::class);

    $duree = $service->getWorkoutDistributions($user)['duration'];
    $historique = $service->getDurationHistory($user);

    // Vingt-neuf minutes et demie, tronquees a vingt-neuf : la seance est donc
    // SOUS la barre des trente, dans les deux lectures.
    expect($historique[0]->duration)->toBe(29)
        ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $stat): int => $stat->count, $duree))->toBe([1, 0, 0, 0]);
});
