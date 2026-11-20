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
 * Trente-et-une séances faites, écrites sans écouteurs : ceux d'une séance
 * (série de jours, succès, objectifs) ne disent rien de cette borne.
 */
it('trace la durée des trente dernières séances terminées, pas une de plus', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();

    Workout::withoutEvents(function () use ($user): void {
        foreach (range(0, 30) as $jour) {
            Workout::factory()->faite()->create([
                'user_id' => $user->id,
                'name' => sprintf('S%02d', $jour),
                'started_at' => Carbon::parse('2026-05-31 10:00:00')->addDays($jour),
                'ended_at' => Carbon::parse('2026-05-31 11:00:00')->addDays($jour),
            ]);
        }
    });

    /** @var list<DurationHistoryPoint> $durees */
    $durees = app(GetStatsDashboardAction::class)->performanceOverview($user, 30)['durationHistory'];

    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $durees))
        ->toBe(array_map(fn (int $jour): string => sprintf('S%02d', $jour), range(1, 30)));
});

/*
 * La racine de Progrès ne porte plus la pesée : elle vit sur la page Corps
 * (`FetchCorpsActionTest`, où sont passés les trois chiffres qu'une seule
 * lecture du cache nourrissait). Ni les props immédiates ni le groupe différé
 * n'en parlent, et rien ne lit le cache des mesures, fermetures résolues ou non.
 */
it('ne rend aucune prop de pesée et ne lit pas le cache des mesures', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();
    \App\Models\BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80.4, 'body_fat' => 18.5, 'measured_at' => '2026-06-29 08:00:00']);
    Cache::flush();

    $lectures = 0;
    Event::listen([CacheHit::class, CacheMissed::class], static function (CacheHit|CacheMissed $lecture) use (&$lectures): void {
        if (str_contains($lecture->key, 'latest_metrics') || str_contains($lecture->key, 'body_progress')) {
            $lectures++;
        }
    });

    $props = app(GetStatsDashboardAction::class)->execute($user, Request::create('/stats', 'GET', ['period' => '7j']));

    expect(array_keys($props))->toBe(['exercises', 'selectedPeriod', 'deferredData'])
        ->and($props['selectedPeriod'])->toBe('7j');

    $props['exercises']();
    $differees = $props['deferredData']();

    expect(array_keys($differees))->toBe(['performance'])
        ->and($lectures)->toBe(0);
});
