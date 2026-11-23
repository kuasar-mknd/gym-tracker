<?php

declare(strict_types=1);

/*
 * Le tableau de bord etait entierement execute par `DashboardTest`, et presque
 * rien n'y etait verifie : 21 des 28 mutants de `FetchDashboardDataAction`
 * survivaient, soit un score de 25 %.
 *
 * Ce que chacun laissait passer, concretement — toutes ces reecritures
 * passaient la suite au vert : retirer du tableau rendu la seance en cours ou
 * les records recents, rendre la seance en cours systematiquement nulle, vider
 * les statistiques analytiques ou le volume hebdomadaire, retirer une de leurs
 * cles, afficher un record ou un objectif de plus (ou de moins) que les deux
 * annonces, retirer l'unite attachee aux objectifs, garder le cache neuf ou
 * onze minutes au lieu de dix.
 *
 * Toutes les valeurs comparees sont POSEES et jamais tirees : les fabriques de
 * `Goal`, de `PersonalRecord` et de `Workout` tirent valeurs et dates au sort,
 * et `PersonalRecordFactory` donne meme `now()` a tout le monde — ce qui rend
 * tout classement par date indecidable si on la laisse faire.
 */

use App\Actions\Dashboard\FetchDashboardDataAction;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Un utilisateur, et l'horloge arretee un jeudi de juin.
 *
 * Le 18 juin 2026 est un jeudi : la semaine courante va du lundi 15 au dimanche
 * 21, la precedente du 8 au 14, et juin se tient loin des deux changements
 * d'heure.
 */
function scenePourTableauDeBord(): User
{
    Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

    return User::factory()->create();
}

it('rend les quatre entrees du tableau de bord, ni plus ni moins', function (): void {
    $user = scenePourTableauDeBord();

    $stats = app(FetchDashboardDataAction::class)->chiffresImmediats($user);

    // Comparer les cles exactes, et non un echantillon : la page lit les
    // quatre, et retirer « recentPRs » du tableau ne se voyait nulle part.
    expect(array_keys($stats))->toBe([
        'recentWorkouts',
        'recentPRs',
        'activeGoals',
    ]);
});

it('rend exactement les deux records les plus recents, du plus recent au plus ancien', function (): void {
    $user = scenePourTableauDeBord();

    $ancien = PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'achieved_at' => Carbon::parse('2026-06-10 10:00:00'),
    ]);
    $milieu = PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'achieved_at' => Carbon::parse('2026-06-12 10:00:00'),
    ]);
    $recent = PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'achieved_at' => Carbon::parse('2026-06-14 10:00:00'),
    ]);

    $records = app(FetchDashboardDataAction::class)->chiffresImmediats($user)['recentPRs']();

    // Deux, sur trois disponibles : c'est la seule assertion qui tienne la
    // borne. Avec trois records en base, `take(1)` comme `take(3)` passaient.
    expect($records)->toHaveCount(2)
        ->and($records->pluck('id')->all())->toBe([$recent->id, $milieu->id])
        ->and($records->pluck('id')->all())->not->toContain($ancien->id)
        // L'exercice est charge d'avance : la carte de record affiche son nom,
        // et `Model::shouldBeStrict()` ferait lever une lecture paresseuse.
        ->and($records->first()->relationLoaded('exercise'))->toBeTrue();
});

it('departage par le plus recent les records d\'une meme seance, qui partagent leur date', function (): void {
    $user = scenePourTableauDeBord();
    $date = Carbon::parse('2026-06-10 10:00:00');

    $premier = PersonalRecord::factory()->create(['user_id' => $user->id, 'achieved_at' => $date]);
    $deuxieme = PersonalRecord::factory()->create(['user_id' => $user->id, 'achieved_at' => $date]);
    $troisieme = PersonalRecord::factory()->create(['user_id' => $user->id, 'achieved_at' => $date]);

    $records = app(FetchDashboardDataAction::class)->chiffresImmediats($user)['recentPRs']();

    expect($records->pluck('id')->all())->toBe([$troisieme->id, $deuxieme->id])
        ->and($records->pluck('id')->all())->not->toContain($premier->id);
});

it('rend exactement les deux objectifs actifs les plus recents, unite comprise', function (): void {
    Queue::fake();
    $user = scenePourTableauDeBord();

    $ancien = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'weight',
        'created_at' => Carbon::parse('2026-06-01 10:00:00'),
    ]);
    $milieu = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'weight',
        'created_at' => Carbon::parse('2026-06-05 10:00:00'),
    ]);
    $recent = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'weight',
        'created_at' => Carbon::parse('2026-06-09 10:00:00'),
    ]);

    // Termine, et le plus recent de tous : il ne doit pas prendre une place.
    Goal::factory()->completed()->create([
        'user_id' => $user->id,
        'type' => 'weight',
        'created_at' => Carbon::parse('2026-06-17 10:00:00'),
    ]);

    $objectifs = app(FetchDashboardDataAction::class)->chiffresImmediats($user)['activeGoals']();

    expect($objectifs)->toHaveCount(2)
        ->and($objectifs->pluck('id')->all())->toBe([$recent->id, $milieu->id])
        ->and($objectifs->pluck('id')->all())->not->toContain($ancien->id);

    // L'unite est ajoutee a la serialisation, sur TOUTES les entrees et pas
    // seulement la premiere : sans elle, la page affiche un objectif de 100
    // sans dire 100 de quoi.
    expect($objectifs->map(fn (Goal $objectif): mixed => $objectif->toArray()['unit'] ?? null)->all())
        ->toBe(['kg', 'kg']);
});

it('rend le volume de la semaine sous ses deux cles, avec les valeurs qui les remplissent', function (): void {
    $user = scenePourTableauDeBord();

    // Semaine precedente (8 au 14 juin), avant jeudi 12h00 de sa semaine : 100.
    Workout::withoutEvents(fn () => Workout::factory()->faite()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-10 10:00:00'),
        'workout_volume' => 100,
    ]));

    // Semaine courante (15 au 21 juin), avant jeudi 12h00 : 250 en deux seances.
    foreach ([['2026-06-16 10:00:00', 150], ['2026-06-17 10:00:00', 100]] as [$debut, $volume]) {
        Workout::withoutEvents(fn () => Workout::factory()->faite()->create([
            'user_id' => $user->id,
            'started_at' => Carbon::parse($debut),
            'workout_volume' => $volume,
        ]));
    }

    $volume = app(FetchDashboardDataAction::class)->getWeeklyVolumeData($user);

    expect(array_keys($volume))->toBe(['stats', 'trend'])
        ->and(array_keys($volume['stats']))->toBe(['current_week_volume', 'percentage', 'sessions_count']);

    // 250.0 et non 250 : `SUM()` revient de MySQL en chaine, et `toBe` compare
    // a l'identique — la valeur et le type d'un coup.
    expect($volume['stats']['current_week_volume'])->toBe(250.0)
        ->and($volume['stats']['current_week_volume'])->toBeFloat()
        // +150 % : 250 contre 100 la semaine d'avant a la meme date. Une valeur
        // posee des deux cotes, pas celle que la fabrique aurait tiree.
        ->and($volume['stats']['percentage'])->toBe(150.0)
        ->and($volume['stats']['sessions_count'])->toBe(2);

    // Sept points, un par jour de la semaine : la cle « trend » disparaissait
    // sans que rien ne la reclame.
    expect($volume['trend'])->toHaveCount(7);
});

it('assemble les statistiques analytiques sous leur seule cle, la carte de la semaine', function (): void {
    $user = scenePourTableauDeBord();

    $analytiques = app(FetchDashboardDataAction::class)->getAnalyticalStats($user);

    // Le tableau rendu n'est ni vide ni ampute : la page lit ces cles
    // nommement, et les repartitions de duree et d'heure n'y sont plus.
    expect(array_keys($analytiques))->toBe(['weeklyVolume'])
        ->and(array_keys($analytiques['weeklyVolume']))->toBe(['stats', 'trend']);
});

it('garde les statistiques analytiques dix minutes, ni neuf ni onze', function (): void {
    $user = scenePourTableauDeBord();
    // La clef porte la semaine de la carte qu'elle garde (#2033) : la 25e de
    // 2026, celle du jeudi 18 juin.
    $cle = \App\Services\Stats\ClesDeStats::seances($user, 'dashboard_weekly_card.2026-25');

    app(FetchDashboardDataAction::class)->getAnalyticalStats($user);

    expect(Cache::get($cle))->not->toBeNull();

    // A neuf minutes et demie l'entree doit tenir : une duree de neuf minutes
    // l'aurait deja laissee expirer.
    Carbon::setTestNow(Carbon::parse('2026-06-18 12:09:30'));
    expect(Cache::get($cle))->not->toBeNull();

    // A dix minutes et demie elle doit avoir disparu : onze minutes la
    // garderaient encore, et le tableau de bord servirait des chiffres perimes.
    Carbon::setTestNow(Carbon::parse('2026-06-18 12:10:30'));
    expect(Cache::get($cle))->toBeNull();
});
