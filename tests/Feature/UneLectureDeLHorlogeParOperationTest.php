<?php

declare(strict_types=1);

/*
 * Une opération lit l'horloge une fois, et passe cet instant à tout ce qu'elle
 * en tire (#2033).
 *
 * Deux lectures pour une même décision se contredisent quand minuit passe
 * entre elles : la séance ouverte à minuit portait le nom du lendemain et la
 * date de la veille, les cartes voisines de la page des séances comptaient
 * deux fenêtres différentes, et le calendrier ouvert le soir du 31 décembre
 * montrait janvier de l'année qui s'achève. Après #2017, qui avait réglé les
 * deux bornes d'une même fenêtre, ces tests font passer minuit, ou la fin du
 * mois et de l'année, entre deux lectures, par l'horloge de
 * `tests/Support/HorlogeQuiPasseMinuit.php`.
 */

use App\Actions\Calendar\FetchCalendarEventsAction;
use App\Actions\Workouts\CreateWorkoutAction;
use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\DTOs\Stats\MonthlyVolumePoint;
use App\Models\User;
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
