<?php

declare(strict_types=1);

use App\Actions\Stats\GetStatsDashboardAction;
use App\DTOs\Stats\DurationHistoryPoint;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
 * La vue « performance » de la page Statistiques trace la durée des trente
 * dernières séances terminées, de la plus ancienne à la plus récente. Rien ne
 * tenait ce nombre : vingt-neuf ou trente-et-une passaient.
 *
 * Trente-et-une séances, écrites d'une seule requête : les écouteurs d'une
 * séance (série de jours, succès, objectifs) ne disent rien de cette borne.
 */
it('trace la durée des trente dernières séances terminées, pas une de plus', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();

    Workout::insert(array_map(fn (int $jour): array => [
        'user_id' => $user->id,
        'name' => sprintf('S%02d', $jour),
        'started_at' => Carbon::parse('2026-05-31 10:00:00')->addDays($jour),
        'ended_at' => Carbon::parse('2026-05-31 11:00:00')->addDays($jour),
        'created_at' => now(),
        'updated_at' => now(),
    ], range(0, 30)));

    /** @var list<DurationHistoryPoint> $durees */
    $durees = app(GetStatsDashboardAction::class)->performanceOverview($user, 30)['durationHistory'];

    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $durees))
        ->toBe(array_map(fn (int $jour): string => sprintf('S%02d', $jour), range(1, 30)));
});

/*
 * Les chiffres immédiats de la page sont des fermetures (#2148, étape 3a) :
 * l'action ne lit rien avant qu'Inertia ne les résolve, et les trois chiffres
 * de la pesée (dernier poids, écart, masse grasse) viennent d'une seule
 * lecture du cache, faite par la première fermeture résolue, quel que soit
 * l'ordre.
 */
it('ne lit la pesée qu’une fois pour ses trois chiffres, et rien avant qu’ils soient résolus', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();
    \App\Models\BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80.4, 'body_fat' => 18.5, 'measured_at' => '2026-06-29 08:00:00']);
    \App\Models\BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 81.0, 'body_fat' => 19.0, 'measured_at' => '2026-06-22 08:00:00']);
    Cache::flush();

    $lectures = 0;
    $prefixe = "stats.latest_metrics.{$user->id}.";
    Event::listen([CacheHit::class, CacheMissed::class], static function (CacheHit|CacheMissed $lecture) use (&$lectures, $prefixe): void {
        if (str_starts_with($lecture->key, $prefixe)) {
            $lectures++;
        }
    });

    $props = app(GetStatsDashboardAction::class)->execute($user, Request::create('/stats', 'GET', ['period' => '7j']));
    expect($lectures)->toBe(0);

    expect($props['bodyFat']())->toBe('18.50')
        ->and($props['weightChange']())->toBe(-0.6)
        ->and($props['latestWeight']())->toBe('80.40')
        ->and($props['selectedPeriod'])->toBe('7j')
        ->and($lectures)->toBe(1);
});
