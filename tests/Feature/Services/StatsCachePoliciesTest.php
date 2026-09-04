<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\StatsCacheManager;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * Ce que les caches de statistiques promettent : une duree, et une invalidation.
 *
 * Les deux sont invisibles a l'oeil nu — une valeur en cache est juste, seulement
 * perimee — et c'est ce qui les rendait libres. Quinze mutants survivaient sur
 * ces trois classes, dont six sur la seule duree de vie de la version de 1RM.
 */

function utilisateurAvecSeance(): User
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    return $user->refresh();
}

/**
 * La version de 1RM vit trente jours, et c'est ce qui limite la duree pendant
 * laquelle une ancienne courbe peut ressortir du cache.
 *
 * Six mutants portaient cette seule expression : la multiplication changee en
 * division ramenait la duree a quarante-huit minutes, et chacun des deux nombres
 * pouvait deriver d'une unite sans que rien ne bronche.
 */
it('garde la version de 1RM trente jours', function (): void {
    $user = User::factory()->create();

    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    app(StatsCacheManager::class)->clearVolumeStats($user);

    $cle = "stats.1rm_version.{$user->id}";

    expect(Cache::has($cle))->toBeTrue('la version n’a pas été écrite');

    Carbon::setTestNow($maintenant->copy()->addDays(30)->subSecond());
    expect(Cache::has($cle))->toBeTrue('la version a expiré avant trente jours');

    Carbon::setTestNow($maintenant->copy()->addDays(30)->addSecond());
    expect(Cache::has($cle))->toBeFalse('la version a survécu au-delà de trente jours');

    Carbon::setTestNow();
});

/**
 * Vider les statistiques de seance vide AUSSI les durees.
 *
 * `clearWorkoutRelatedStats` enchaine deux nettoyages. Retirer le second laissait
 * l'historique de duree en cache apres un changement de seance — un graphique
 * juste, mais perime, et rien ne le disait.
 */
it('vide l’historique de durée avec les statistiques de séance', function (): void {
    $user = utilisateurAvecSeance();

    app(WorkoutStatsService::class)->getDurationHistory($user);

    $cle = "stats.duration_history.{$user->id}.20";

    expect(Cache::has($cle))->toBeTrue();

    app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);

    expect(Cache::has($cle))->toBeFalse('l’historique de durée est resté en cache');
});

/**
 * La vue d'ensemble delegue, et ce qu'elle delegue doit arriver.
 *
 * Le mutant qui faisait rendre un tableau vide a la repartition survivait :
 * rien ne verifiait que le service transmet la repartition telle quelle.
 */
it('transmet la répartition des séances au lieu d’un tableau vide', function (): void {
    $user = utilisateurAvecSeance();

    $repartitions = app(WorkoutStatsService::class)->getWorkoutDistributions($user);

    expect($repartitions)->toHaveKeys(['duration', 'time_of_day'])
        ->and($repartitions['duration'])->not->toBeEmpty()
        ->and($repartitions['time_of_day'])->not->toBeEmpty();
});

/**
 * Les mesures corporelles datent chaque point de leur propre jour.
 *
 * Le repli sur un horodatage illisible ne pouvait pas se declencher —
 * `measured_at` est NOT NULL — et faisait survivre deux mutants.
 */
it('date chaque point de l’historique de poids', function (): void {
    $user = User::factory()->create();

    /*
     * L'horloge est arretee, comme au temoin de duree de vie plus haut.
     * L'historique est borne a `now()->subDays(90)` : une date absolue reste
     * dans la fenetre le jour ou on l'ecrit, puis en sort toute seule. Celle-ci
     * en est sortie le 01/09/2026 — 90 jours pile apres le 03/06 — et le test a
     * echoue sur une PR qui ne touchait ni aux statistiques ni aux dates.
     */
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'measured_at' => '2026-06-03',
        'weight' => 78.5,
    ]);

    $historique = app(BodyStatsService::class)->getBodyProgressOverview($user);

    expect($historique['weightHistory'][0]->date)->toBe('03/06')
        ->and($historique['weightHistory'][0]->full_date)->toBe('2026-06-03');
});
