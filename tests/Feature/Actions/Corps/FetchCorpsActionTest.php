<?php

declare(strict_types=1);

use App\Actions\Corps\FetchCorpsAction;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
 * Les props de la page Corps sont des fermetures (#2148, étape 3a) : l'action
 * ne lit rien avant qu'Inertia ne les résolve, et les trois chiffres de la
 * pesée (dernier poids, écart, masse grasse) viennent d'une seule lecture du
 * cache, faite par la première fermeture résolue, quel que soit l'ordre. Ces
 * chiffres étaient ceux de la racine de Progrès, et le test qui les tenait
 * (`GetStatsDashboardActionTest`) est passé ici avec eux.
 */
it('ne lit la pesée qu’une fois pour ses trois chiffres, et rien avant qu’ils soient résolus', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80.4, 'body_fat' => 18.5, 'measured_at' => '2026-06-29 08:00:00']);
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 81.0, 'body_fat' => 19.0, 'measured_at' => '2026-06-22 08:00:00']);
    Cache::flush();

    $lectures = 0;
    $prefixe = "stats.latest_metrics.{$user->id}.";
    Event::listen([CacheHit::class, CacheMissed::class], static function (CacheHit|CacheMissed $lecture) use (&$lectures, $prefixe): void {
        if (str_starts_with($lecture->key, $prefixe)) {
            $lectures++;
        }
    });

    $props = app(FetchCorpsAction::class)->execute($user);
    expect($lectures)->toBe(0);

    expect($props['bodyFat']())->toBe('18.50')
        ->and($props['weightChange']())->toBe(-0.6)
        ->and($props['latestWeight']())->toBe('80.40')
        ->and($lectures)->toBe(1);
});

it('rend les courbes sur quatre-vingt-dix jours, et pas une pesée plus ancienne', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80.4, 'body_fat' => 18.5, 'measured_at' => '2026-06-29 08:00:00']);
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 90.0, 'body_fat' => 25.0, 'measured_at' => '2026-02-01 08:00:00']);

    $courbes = app(FetchCorpsAction::class)->execute($user)['bodyStats']();

    expect($courbes['weightHistory'])->toHaveCount(1)
        ->and($courbes['bodyFatHistory'])->toHaveCount(1)
        ->and($courbes['weightHistory'][0]->full_date)->toBe('2026-06-29');
});

it('rend la dernière mensuration de chaque partie, celles du compte seulement', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Waist', 'value' => 82, 'measured_at' => '2026-06-01']);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Waist', 'value' => 81, 'measured_at' => '2026-06-20']);
    BodyPartMeasurement::factory()->create(['user_id' => User::factory()->create()->id, 'part' => 'Chest', 'value' => 100]);

    $mensurations = app(FetchCorpsAction::class)->execute($user)['latestMeasurements']();

    $partie = $mensurations->first();
    assert($partie !== null);

    expect($mensurations)->toHaveCount(1)
        ->and($partie['part'])->toBe('Waist')
        ->and($partie['current'])->toBe(81.0)
        ->and($partie['diff'])->toBe(-1.0);
});
