<?php

declare(strict_types=1);

use App\DTOs\Stats\MonthlyVolumePoint;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\VolumeStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
    'historique de volume' => ['getVolumeHistory', 'volume_history.20', 30],
    'historique mensuel' => ['getMonthlyVolumeHistory', 'monthly_volume_history.6', 30],
]);

/**
 * La fenetre du graphique mensuel : six mois, le sixieme compris.
 *
 * `subMonths($months - 1)->startOfMonth()` decide du bord, et `range($months - 1, 0)`
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
