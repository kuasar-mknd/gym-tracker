<?php

declare(strict_types=1);

use App\Actions\Stats\GetStatsDashboardAction;
use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\DTOs\Stats\MonthlyVolumePoint;
use App\DTOs\Stats\VolumeComparison;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\VolumeStatsService;
use Illuminate\Support\Carbon;

/*
 * Les graphiques mensuels et la comparaison mensuelle reculent de mois en mois
 * depuis le PREMIER du mois courant (#1955).
 *
 * Ils reculaient depuis aujourd'hui. Or Carbon déborde : le 31 octobre moins un
 * mois donne le « 31 septembre », c'est-à-dire le 1er octobre. Les 29, 30 et 31,
 * les deux graphiques de la page des séances perdaient des mois et en doublaient
 * d'autres, et la comparaison de la page Statistiques prenait le mois courant
 * pour le mois précédent.
 */

/**
 * Une séance terminée, d'un volume donné, à une date donnée.
 */
function seanceDuMoisAvecVolume(User $user, string $quand, float $poids, int $repetitions): Workout
{
    $seance = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse($quand),
        'ended_at' => Carbon::parse($quand)->addHour(),
    ]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);
    Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
    ]);

    return $seance;
}

/**
 * Six mois distincts et consécutifs dans les deux graphiques de la page des
 * séances, à une date où le recul depuis aujourd'hui débordait.
 *
 * @param  array<string, float>  $seances  Le poids soulevé dix fois, par date de séance.
 * @param  list<array{0: string, 1: int, 2: float}>  $moisAttendus  Chaque barre : son mois, ses séances, son volume.
 */
function lesSixMoisDesGraphiquesLe(string $maintenant, array $seances, array $moisAttendus): void
{
    Carbon::setTestNow(Carbon::parse($maintenant));
    $user = User::factory()->create();

    foreach ($seances as $quand => $poids) {
        seanceDuMoisAvecVolume($user, $quand, $poids, 10);
    }

    $graphiques = app(FetchWorkoutsIndexAction::class)->getDeferredData($user)['charts'];

    expect($graphiques['monthly_frequency']->all())->toBe(array_map(
        fn (array $mois): array => ['month' => $mois[0], 'count' => $mois[1]],
        $moisAttendus,
    ));

    expect(array_map(fn (MonthlyVolumePoint $point): array => [$point->month, $point->volume], $graphiques['monthly_volume']))
        ->toBe(array_map(fn (array $mois): array => [$mois[0], $mois[2]], $moisAttendus));
}

/*
 * Chaque cas porte une séance dans un mois que l'ancien calcul faisait
 * disparaître : juin et septembre le 31 octobre, février le 30 mars. Le
 * 31 juillet, février sortait en plus de la fenêtre SQL : le « 31 février »
 * devenait le 3 mars, et la fenêtre commençait au 1er mars.
 */
it('montre six mois distincts et consécutifs le 31 octobre', function (): void {
    lesSixMoisDesGraphiquesLe('2026-10-31 12:00:00', [
        '2026-06-10 10:00:00' => 100.0,
        '2026-09-10 10:00:00' => 80.0,
    ], [
        ['mai', 0, 0.0],
        ['juin', 1, 1000.0],
        ['juil.', 0, 0.0],
        ['août', 0, 0.0],
        ['sept.', 1, 800.0],
        ['oct.', 0, 0.0],
    ]);
});

it('montre six mois distincts et consécutifs le 30 mars', function (): void {
    lesSixMoisDesGraphiquesLe('2026-03-30 12:00:00', [
        '2026-02-10 10:00:00' => 100.0,
        '2026-03-05 10:00:00' => 50.0,
    ], [
        ['oct.', 0, 0.0],
        ['nov.', 0, 0.0],
        ['déc.', 0, 0.0],
        ['janv.', 0, 0.0],
        ['févr.', 1, 1000.0],
        ['mars', 1, 500.0],
    ]);
});

it('garde février dans la fenêtre le 31 juillet', function (): void {
    lesSixMoisDesGraphiquesLe('2026-07-31 12:00:00', [
        '2026-02-10 10:00:00' => 100.0,
        '2026-07-05 10:00:00' => 50.0,
    ], [
        ['févr.', 1, 1000.0],
        ['mars', 0, 0.0],
        ['avr.', 0, 0.0],
        ['mai', 0, 0.0],
        ['juin', 0, 0.0],
        ['juil.', 1, 500.0],
    ]);
});

/**
 * La comparaison mensuelle met le mois courant face au mois qui le précède,
 * même un jour que le mois précédent n'a pas.
 */
it('compare le mois courant au mois précédent, même le 30 ou le 31', function (string $maintenant, string $moisPrecedent, string $moisCourant): void {
    Carbon::setTestNow(Carbon::parse($maintenant));
    $user = User::factory()->create();

    seanceDuMoisAvecVolume($user, $moisPrecedent, 100, 10);  // 1000
    seanceDuMoisAvecVolume($user, $moisCourant, 50, 10);     // 500

    $comparaison = app(VolumeStatsService::class)->getMonthlyVolumeComparison($user);

    expect($comparaison)->toEqual(new VolumeComparison(500.0, 1000.0, -500.0, -50.0));
})->with([
    '31 octobre' => ['2026-10-31 12:00:00', '2026-09-10 10:00:00', '2026-10-05 10:00:00'],
    '30 mars' => ['2026-03-30 12:00:00', '2026-02-10 10:00:00', '2026-03-05 10:00:00'],
    '31 mai' => ['2026-05-31 12:00:00', '2026-04-30 10:00:00', '2026-05-01 10:00:00'],
]);

/**
 * Le changement de mois ne sert pas la fenêtre de la veille.
 *
 * Les entrées en cache dont la fenêtre suit le mois calendaire portent ce mois
 * dans leur clef, comme la comparaison hebdomadaire porte sa semaine : sans
 * cela, le 1er novembre à minuit cinq, la page servait encore les six mois
 * finissant en octobre, et la comparaison d'octobre à septembre, jusqu'à
 * l'expiration de l'entrée.
 */
it('recalcule les statistiques mensuelles au premier jour du mois, sans attendre le cache', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-31 23:50:00'));
    $user = User::factory()->create();

    seanceDuMoisAvecVolume($user, '2026-05-12 10:00:00', 100, 10);  // un mardi de mai : sort de la fenêtre en novembre
    seanceDuMoisAvecVolume($user, '2026-10-05 10:00:00', 50, 10);

    $index = app(FetchWorkoutsIndexAction::class);
    $tableauDeBord = app(GetStatsDashboardAction::class);

    $index->getDeferredData($user);
    $tableauDeBord->performanceOverview($user, 30);

    Carbon::setTestNow(Carbon::parse('2026-11-01 00:05:00'));

    $graphiques = $index->getDeferredData($user)['charts'];
    $comparaison = $tableauDeBord->performanceOverview($user, 30)['monthlyComparison'];

    expect($graphiques['monthly_frequency']->pluck('month')->all())
        ->toBe(['juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.'])
        ->and(array_map(fn (MonthlyVolumePoint $point): string => $point->month, $graphiques['monthly_volume']))
        ->toBe(['juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.'])
        ->and($graphiques['day_of_week_frequency']->firstWhere('day', 'Mar'))
        ->toBe(['day' => 'Mar', 'count' => 0])
        ->and($comparaison)
        ->toEqual(new VolumeComparison(0.0, 500.0, -500.0, -100.0));
});
