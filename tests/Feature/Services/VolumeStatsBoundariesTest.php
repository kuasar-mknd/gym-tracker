<?php

declare(strict_types=1);

use App\DTOs\Stats\MonthlyVolumePoint;
use App\DTOs\Stats\WeeklyVolumeTrendPoint;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\VolumeStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\HorlogeQuiPasseMinuit;

/*
 * Les bornes de VolumeStatsService, ou 39 mutants survivaient.
 *
 * Trois familles : la duree de vie des caches, que rien ne verifiait ; la fenetre
 * du graphique mensuel, dont ni le debut ni le nombre de points n'etaient
 * contraints ; et le pourcentage d'evolution, dont la borne et la precision
 * etaient libres.
 */

/**
 * Une seance d'un volume donne, a une date donnee.
 */
function seanceDeVolume(User $user, Carbon $quand, float $poids, int $repetitions): Workout
{
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => $quand]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
    ]);

    return $workout;
}

/**
 * La duree de vie de chaque cache, encadree des deux cotes.
 *
 * Un seul instant ne separerait rien : c'est le couple « juste avant » et « juste
 * apres » qui fixe la valeur. Les tests de cache existants verifient qu'une clé
 * est presente ou absente, jamais combien de temps elle le reste — les dix
 * mutants qui portaient ces durees a une minute de plus ou de moins survivaient
 * tous.
 */
it('garde chaque statistique en cache la durée annoncée', function (string $methode, string $cle, int $minutes): void {
    $user = User::factory()->create();

    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    seanceDeVolume($user, $maintenant->copy()->subDays(2), 100, 10);

    app(VolumeStatsService::class)->{$methode}($user->refresh());

    $cleComplete = \App\Services\Stats\ClesDeStats::seances($user, $cle);

    expect(Cache::has($cleComplete))->toBeTrue('la clé attendue n’a pas été écrite');

    Carbon::setTestNow($maintenant->copy()->addMinutes($minutes)->subSecond());
    expect(Cache::has($cleComplete))->toBeTrue('l’entrée a expiré trop tôt');

    Carbon::setTestNow($maintenant->copy()->addMinutes($minutes)->addSecond());
    expect(Cache::has($cleComplete))->toBeFalse('l’entrée a survécu au-delà de sa durée');

    Carbon::setTestNow();
})->with([
    'tendance de volume' => ['getVolumeTrend', 'volume_trend.30', 30],
    'volume hebdomadaire' => ['getWeeklyVolumeTrend', 'weekly_volume', 10],
    // La clef porte la semaine courante : la 25e de 2026, celle du 15 juin.
    'comparaison hebdomadaire' => ['getWeeklyVolumeComparison', 'weekly_volume_comparison.2026-25', 10],
    'historique de volume' => ['getVolumeHistory', 'volume_history.20', 30],
    // La clef porte le mois courant (#1955) : celui de l'horloge arrêtée.
    'historique mensuel' => ['getMonthlyVolumeHistory', 'monthly_volume_history.6.2026-06', 30],
]);

/**
 * La fenetre du graphique mensuel : six mois, le sixieme compris.
 *
 * `$debutDuMois->copy()->subMonths($months - 1)` decide du bord, et `range($months - 1, 0)`
 * du nombre de points. Ni l'un ni l'autre n'etait contraint : cinq mutants y
 * survivaient, dont un qui changeait la soustraction en addition.
 */
it('couvre exactement les six derniers mois, bornes comprises', function (): void {
    $user = User::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    // Le mois le plus ancien de la fenetre : janvier, six mois avant juin inclus.
    seanceDeVolume($user, Carbon::parse('2026-01-20 10:00:00'), 100, 10);   // 1000
    // Juste avant la fenetre : decembre ne doit pas compter.
    seanceDeVolume($user, Carbon::parse('2025-12-20 10:00:00'), 50, 10);    // 500

    $points = app(VolumeStatsService::class)->getMonthlyVolumeHistory($user->refresh());

    $volumes = array_map(fn (MonthlyVolumePoint $point): float => $point->volume, $points);

    expect($points)->toHaveCount(6)
        // Janvier en tête, juin en queue, et le volume de décembre nulle part.
        ->and($volumes[0])->toBe(1000.0)
        ->and(array_sum($volumes))->toBe(1000.0);

    Carbon::setTestNow();
});

/**
 * Un mois sans seance vaut zero, pas un trou.
 *
 * Le repli qui le produit portait deux mutants : a -1 le graphique descendrait
 * sous l'axe, a 1 il inventerait un kilo de volume sur un mois ou personne ne
 * s'est entraine.
 */
it('donne zéro à un mois sans séance', function (): void {
    $user = User::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    seanceDeVolume($user, Carbon::parse('2026-06-10 10:00:00'), 100, 10);

    $points = app(VolumeStatsService::class)->getMonthlyVolumeHistory($user->refresh());

    // Mai n'a rien : avant-dernier point.
    expect($points[4]->volume)->toBe(0.0);

    Carbon::setTestNow();
});

/**
 * La semaine de la tendance se tire d'une seule lecture de l'horloge.
 *
 * Son dimanche venait d'une seconde lecture : quand lundi commençait entre les
 * deux, la tendance comptait quatorze jours, du lundi d'avant au dimanche
 * d'après, et le cache les gardait dix minutes. L'horloge passe minuit après
 * chacune des lectures, puisque l'endroit exact dépend de l'ordre des appels,
 * cache compris (#2017).
 */
it('rend les sept jours d une seule semaine quand lundi commence pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-11 23:59:59.999999', '2026-10-12 00:00:00.000001');

    $tendance = app(VolumeStatsService::class)->getWeeklyVolumeTrend($user);

    expect(array_map(static fn (WeeklyVolumeTrendPoint $point): string => $point->date, $tendance))->toBeIn([
        ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'],
        ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'],
    ]);
})->with([1, 2, 3, 4]);

/**
 * La comparaison de la semaine se tire, elle aussi, d'une seule lecture.
 *
 * La semaine courante, le début de la précédente et sa fin venaient de trois
 * lectures : quand lundi commençait entre elles, la semaine qui s'achevait se
 * comparait à elle-même (0 %), ou à elle-même et à celle d'avant réunies
 * (#2017).
 */
it('compare la semaine à la précédente quand lundi commence pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    seanceDeVolume($user, Carbon::parse('2026-09-30 10:00:00'), 100, 10);   // 1000, semaine du 28 septembre
    seanceDeVolume($user, Carbon::parse('2026-10-07 10:00:00'), 50, 10);    // 500, semaine du 5 octobre
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-11 23:59:59.999999', '2026-10-12 00:00:00.000001');

    $comparaison = app(VolumeStatsService::class)->getWeeklyVolumeComparison($user->refresh());

    // La semaine du 5 contre celle du 28, ou celle du 12, encore vide, contre
    // celle du 5.
    expect([$comparaison->current_volume, $comparaison->previous_volume])->toBeIn([[500.0, 1000.0], [0.0, 500.0]]);
})->with([1, 2, 3, 4, 5]);
