<?php

declare(strict_types=1);

/*
 * Une opération lit l'horloge une fois, et passe cet instant à tout ce qu'elle
 * en tire (#2033).
 *
 * Deux lectures pour une même décision se contredisent quand minuit passe
 * entre elles : la séance ouverte à minuit portait le nom du lendemain et la
 * date de la veille, les cartes voisines de la page des séances comptaient
 * deux fenêtres différentes, la page Statistiques rangeait sous un mois la
 * comparaison du suivant, le calendrier ouvert le soir du 31 décembre montrait
 * janvier de l'année qui s'achève, la carte hebdomadaire du tableau de bord
 * posait le total d'une semaine sur la courbe d'une autre, et la page
 * Hydratation montrait au compteur un autre jour que sur son graphique.
 * Après #2017, qui avait réglé les deux bornes d'une même fenêtre, ces tests
 * font passer minuit, ou la fin du mois et de l'année, entre deux lectures,
 * par l'horloge de `tests/Support/HorlogeQuiPasseMinuit.php`.
 */

use App\Actions\Calendar\FetchCalendarEventsAction;
use App\Actions\Dashboard\FetchDashboardDataAction;
use App\Actions\Stats\GetStatsDashboardAction;
use App\Actions\Tools\FetchWaterTrackerAction;
use App\Actions\Workouts\CreateWorkoutAction;
use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\DTOs\Stats\MonthlyVolumePoint;
use App\DTOs\Stats\VolumeComparison;
use App\DTOs\Stats\WeeklyVolumeTrendPoint;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\Workout;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\HorlogeQuiPasseMinuit;

it('nomme la séance du jour où elle commence quand minuit passe pendant sa création', function (): void {
    $user = User::factory()->create();
    $creation = app(CreateWorkoutAction::class);
    HorlogeQuiPasseMinuit::apres(1, '2026-09-30 23:59:59.999999', '2026-10-01 00:00:00.000001');

    $seance = Workout::query()->findOrFail($creation->execute($user)->id);

    // Le nom par défaut est la date de la séance : le 30 septembre, ou le
    // 1er octobre, mais la même que celle de son début.
    expect($seance->name)->toBe($seance->started_at->format('d/m/Y'));
});

/**
 * Les cartes de la page des séances regardent les mêmes six mois quand le mois
 * change pendant le calcul.
 *
 * La fréquence mensuelle, sa voisine par jour de la semaine et le volume
 * mensuel lisaient chacun l'horloge, et la clef de chaque fréquence ne venait
 * pas de la même lecture que sa fenêtre : quand le 1er octobre commençait
 * entre deux lectures, une carte comptait d'avril à septembre et sa voisine de
 * mai à octobre, ou l'entrée rangée sous septembre gardait la fenêtre
 * d'octobre, et la servait à une requête encore en septembre. L'horloge passe
 * minuit après chacune des dix lectures, cache compris, qui précédaient celle
 * du volume mensuel : après la première ou la deuxième, la clef de la
 * fréquence mensuelle disait septembre ; de la troisième à la septième, les
 * deux fréquences divergeaient ; de la huitième à la dixième, le volume
 * mensuel quittait les fréquences.
 */
it('montre les mêmes six mois sur les cartes de la page des séances quand le mois change pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    Workout::withoutEvents(function () use ($user): void {
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-04-15 10:00:00')]);
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-09-15 10:00:00')]);
    });
    $index = app(FetchWorkoutsIndexAction::class);
    HorlogeQuiPasseMinuit::apres($lectures, '2026-09-30 23:59:59.999999', '2026-10-01 00:00:00.000001');

    $graphiques = $index->getDeferredData($user)['charts'];

    $mois = $graphiques['monthly_frequency']->pluck('month')->all();
    $moisDeLaFenetre = end($mois) === 'sept.' ? '2026-09' : '2026-10';

    // D'avril à septembre, ou de mai à octobre : la séance d'avril compte
    // alors dans les deux fréquences, ou dans aucune.
    expect($mois)->toBeIn([
        ['avr.', 'mai', 'juin', 'juil.', 'août', 'sept.'],
        ['mai', 'juin', 'juil.', 'août', 'sept.', 'oct.'],
    ])
        ->and($graphiques['day_of_week_frequency']->sum('count'))
        ->toBe($graphiques['monthly_frequency']->sum('count'))
        ->and(array_map(static fn (MonthlyVolumePoint $point): string => $point->month, $graphiques['monthly_volume']))
        ->toBe($mois)
        ->and(Cache::has(ClesDeStats::seances($user, 'monthly_frequency.'.$moisDeLaFenetre)))
        ->toBeTrue()
        ->and(Cache::has(ClesDeStats::seances($user, 'day_of_week_frequency.'.$moisDeLaFenetre)))
        ->toBeTrue();
})->with(range(1, 10));

/**
 * La vue « performance » de la page Statistiques range la comparaison
 * mensuelle sous le mois qu'elle compare quand le mois change pendant le
 * calcul.
 *
 * Le mois de la clef venait d'une lecture de l'horloge, la fenêtre de la
 * comparaison d'une autre, faite par le service bien après : quand le
 * 1er octobre commençait entre les deux, l'entrée rangée sous septembre
 * gardait la comparaison d'octobre, rien contre le total de septembre, et la
 * servait pendant une demi-heure à une requête encore en septembre.
 * L'horloge passe minuit après chacune des douze lectures, cache compris, qui
 * précédaient celle de la comparaison.
 */
it('range la comparaison mensuelle de la page Statistiques sous le mois qu elle compare quand le mois change pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    Workout::withoutEvents(function () use ($user): void {
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-08-14 10:00:00'), 'workout_volume' => 500]);
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-09-15 10:00:00'), 'workout_volume' => 1000]);
    });
    $statistiques = app(GetStatsDashboardAction::class);
    HorlogeQuiPasseMinuit::apres($lectures, '2026-09-30 23:59:59.999999', '2026-10-01 00:00:00.000001');

    $comparaison = $statistiques->performanceOverview($user, 30)['monthlyComparison'];
    assert($comparaison instanceof VolumeComparison);

    // Septembre contre août, 1 000 contre 500, ou octobre, vide, contre
    // septembre : l'entrée porte le mois de la comparaison qu'elle garde.
    $moisDeLaComparaison = $comparaison->current_volume === 1000.0 ? '2026-09' : '2026-10';
    expect([$comparaison->current_volume, $comparaison->previous_volume, $comparaison->percentage])->toBeIn([
        [1000.0, 500.0, 100.0],
        [0.0, 1000.0, -100.0],
    ])
        ->and(Cache::has(ClesDeStats::seances($user, 'performance_overview.30.'.$moisDeLaComparaison)))
        ->toBeTrue();
})->with(range(1, 12));

/**
 * Le calendrier ouvert sans mois demandé montre le mois courant de l'année
 * courante, lus d'une seule lecture.
 *
 * L'année et le mois venaient de deux lectures : le soir du 31 décembre, quand
 * l'année changeait entre elles, la page s'ouvrait sur janvier de l'année qui
 * s'achevait, onze mois en arrière. L'horloge passe minuit dès que le
 * contrôleur a reçu son action, juste avant sa première lecture.
 */
it('ouvre le calendrier sur un mois de la bonne année quand l année change pendant la requête', function (): void {
    $user = User::factory()->create();
    Carbon::setTestNow(Carbon::parse('2026-12-31 23:59:59'));
    $this->app->resolving(FetchCalendarEventsAction::class, static function (): void {
        HorlogeQuiPasseMinuit::apres(1, '2026-12-31 23:59:59.999999', '2027-01-01 00:00:00.000001');
    });

    $reponse = $this->actingAs($user)->get(route('calendar.index'));

    $reponse->assertOk();
    expect([$reponse->inertiaProps('year'), $reponse->inertiaProps('month')])->toBeIn([[2026, 12], [2027, 1]]);
});

/**
 * La page Hydratation montre le même jour au compteur et sur la dernière barre
 * de son graphique quand minuit passe pendant la requête.
 *
 * Le compteur du jour et le graphique des sept derniers jours lisaient chacun
 * l'horloge : quand minuit passait entre les deux, le compteur montrait les
 * 2 000 ml et les prises du 11 octobre au-dessus d'un graphique dont la barre
 * du jour, le 12, restait vide, et dont la veille portait ces 2 000 ml.
 * L'horloge passe minuit dès que le contrôleur a reçu ses actions, juste
 * avant sa première lecture.
 */
it('montre le même jour au compteur et au graphique de la page Hydratation quand minuit passe pendant la requête', function (): void {
    $user = User::factory()->create();
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::parse('2026-10-11 12:00:00'), 'amount' => 2000]);
    Carbon::setTestNow(Carbon::parse('2026-10-11 23:59:59'));
    $this->app->resolving(FetchWaterTrackerAction::class, static function (): void {
        HorlogeQuiPasseMinuit::apres(1, '2026-10-11 23:59:59.999999', '2026-10-12 00:00:00.000001');
    });

    $reponse = $this->actingAs($user)->get(route('tools.water.index'));

    $reponse->assertOk();
    $historique = $reponse->inertiaProps('history');
    assert(is_array($historique));
    $barreDuJour = end($historique);
    assert(is_array($barreDuJour));
    $prisesDuJour = $reponse->inertiaProps('logs');
    assert(is_array($prisesDuJour));
    $totalDuJour = $reponse->inertiaProps('todayTotal');
    assert(is_numeric($totalDuJour) && is_numeric($barreDuJour['total']));

    // Le 11 octobre et ses 2 000 ml, ou le 12, vide, au compteur comme sur la
    // barre du jour.
    expect([(float) $totalDuJour, count($prisesDuJour), $barreDuJour['date'], (float) $barreDuJour['total']])->toBeIn([
        [2000.0, 1, '2026-10-11', 2000.0],
        [0.0, 0, '2026-10-12', 0.0],
    ]);
});

/**
 * Deux semaines de séances pour la carte hebdomadaire du tableau de bord.
 *
 * 1 000 la semaine du 28 septembre, 500 celle du 5 octobre, rien encore celle
 * du 12 : la semaine du 5 se lit « 500, -50 % », celle du 12 « 0, -100 % ».
 * Le volume est posé, les écouteurs sont coupés.
 */
function carteHebdomadaireSurDeuxSemaines(User $user): void
{
    Workout::withoutEvents(function () use ($user): void {
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-09-30 10:00:00'), 'workout_volume' => 1000]);
        Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-10-07 10:00:00'), 'workout_volume' => 500]);
    });
}

/**
 * La carte hebdomadaire du tableau de bord montre une seule semaine quand
 * lundi commence pendant le calcul.
 *
 * Le total de la semaine et sa comparaison venaient d'une lecture de
 * l'horloge, la courbe des sept jours d'une autre : quand lundi commençait
 * entre les deux, la carte annonçait les 500 de la semaine qui s'achevait
 * au-dessus de la courbe, vide, de celle qui commençait. La clef de la courbe
 * ne disait pas non plus sa semaine. L'horloge passe minuit après chacune des
 * cinq lectures, cache compris, qui précédaient celle de la courbe.
 */
it('montre une seule semaine sur la carte hebdomadaire du tableau de bord quand lundi commence pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    carteHebdomadaireSurDeuxSemaines($user);
    $tableauDeBord = app(FetchDashboardDataAction::class);
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-11 23:59:59.999999', '2026-10-12 00:00:00.000001');

    $carte = $tableauDeBord->getWeeklyVolumeData($user);

    $courbe = $carte['trend'];
    $semaine = Carbon::parse($courbe[0]->date)->format('Y-W');

    // La semaine du 5 octobre, 500 contre 1 000, ou celle du 12, vide, contre
    // 500 : le total est la somme de la courbe.
    expect([$courbe[0]->date, $carte['stats']['current_week_volume'], $carte['stats']['percentage']])->toBeIn([
        ['2026-10-05', 500.0, -50.0],
        ['2026-10-12', 0.0, -100.0],
    ])
        ->and(array_sum(array_map(static fn (WeeklyVolumeTrendPoint $point): float => $point->volume, $courbe)))
        ->toBe($carte['stats']['current_week_volume'])
        ->and(Cache::has(ClesDeStats::seances($user, 'weekly_volume.'.$semaine)))
        ->toBeTrue()
        ->and(Cache::has(ClesDeStats::seances($user, 'weekly_volume_comparison.'.$semaine)))
        ->toBeTrue();
})->with(range(1, 5));

/**
 * Les analyses du tableau de bord se rangent sous la semaine de la carte
 * qu'elles gardent.
 *
 * Leur clef ne portait pas la semaine, et la carte hebdomadaire qu'elles
 * réunissent lisait l'horloge pour son compte. L'horloge passe minuit après
 * chacune des six lectures qui précédaient celle de la courbe.
 */
it('range les analyses du tableau de bord sous la semaine de leur carte quand lundi commence pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    carteHebdomadaireSurDeuxSemaines($user);
    $tableauDeBord = app(FetchDashboardDataAction::class);
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-11 23:59:59.999999', '2026-10-12 00:00:00.000001');

    $carte = $tableauDeBord->getAnalyticalStats($user)['weeklyVolume'];

    $semaine = Carbon::parse($carte['trend'][0]->date)->format('Y-W');
    expect([$carte['trend'][0]->date, $carte['stats']['current_week_volume']])->toBeIn([['2026-10-05', 500.0], ['2026-10-12', 0.0]])
        ->and(Cache::has(ClesDeStats::seances($user, 'dashboard_analytical.'.$semaine)))
        ->toBeTrue();
})->with(range(1, 6));

/**
 * Le lundi ne sert pas la carte hebdomadaire mise en cache le dimanche soir.
 *
 * Ni les analyses réunies du tableau de bord ni la courbe des sept jours ne
 * portaient la semaine dans leur clef : le tableau de bord ouvert le dimanche
 * à 23:55, puis à 00:02, montrait encore la semaine qui venait de s'achever
 * comme la semaine en cours, jusqu'à l'expiration de l'entrée, dix minutes.
 * Elles la portent désormais, comme la comparaison, et comme le volume mensuel
 * porte son mois (#1955).
 */
it('ne sert pas le lundi la carte hebdomadaire mise en cache le dimanche soir', function (): void {
    $user = User::factory()->create();
    carteHebdomadaireSurDeuxSemaines($user);
    $tableauDeBord = app(FetchDashboardDataAction::class);
    Carbon::setTestNow(Carbon::parse('2026-10-11 23:55:00'));
    $tableauDeBord->getAnalyticalStats($user);
    Carbon::setTestNow(Carbon::parse('2026-10-12 00:02:00'));

    $carte = $tableauDeBord->getAnalyticalStats($user)['weeklyVolume'];

    expect($carte['trend'][0]->date)->toBe('2026-10-12')
        ->and($carte['stats'])->toBe(['current_week_volume' => 0.0, 'percentage' => -100.0]);
});
