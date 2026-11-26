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

/**
 * @param  array<string, array{0: float|int, 1?: string, 2?: string}>  $parties  partie => [valeur, unité, date]
 */
function mensurationsDeCorps(User $user, array $parties): void
{
    foreach ($parties as $partie => $mesure) {
        BodyPartMeasurement::factory()->create([
            'user_id' => $user->id,
            'part' => $partie,
            'value' => $mesure[0],
            'unit' => $mesure[1] ?? 'cm',
            'measured_at' => $mesure[2] ?? '2026-11-12',
        ]);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function lignesDeCorps(User $user): array
{
    return array_values(app(FetchCorpsAction::class)->execute($user)['latestMeasurements']()->all());
}

/**
 * @param  list<array<string, mixed>>  $lignes
 * @return list<string>
 */
function nomsDesLignesDeCorps(array $lignes): array
{
    return array_map(static fn (array $ligne): string => (string) ($ligne['famille'] ?? $ligne['part']), $lignes);
}

it('regroupe les treize parties en six lignes, dont trois paires, dans l’ordre de la carte', function (): void {
    $user = User::factory()->create();
    mensurationsDeCorps($user, array_fill_keys(BodyPartMeasurement::COMMON_PARTS, [40]));

    $lignes = lignesDeCorps($user);

    expect(nomsDesLignesDeCorps($lignes))->toBe(['Waist', 'Chest', 'Hips', 'Thigh', 'Biceps', 'Calf'])
        ->and($lignes[3]['label'])->toBe('Cuisse')
        ->and(array_column($lignes[3]['cotes'], 'part'))->toBe(['Thigh L', 'Thigh R'])
        ->and(array_column($lignes[4]['cotes'], 'cote'))->toBe(['gauche', 'droite'])
        ->and(array_column($lignes[5]['cotes'], 'label'))->toBe(['Mollet gauche', 'Mollet droit']);
});

it('donne à l’avant-bras la place qu’une cuisse et un mollet absents laissent', function (): void {
    $user = User::factory()->create();
    mensurationsDeCorps($user, array_fill_keys(['Neck', 'Shoulders', 'Chest', 'Waist', 'Hips', 'Biceps L', 'Biceps R', 'Forearm L', 'Forearm R'], [40]));

    expect(nomsDesLignesDeCorps(lignesDeCorps($user)))->toBe(['Waist', 'Chest', 'Hips', 'Biceps', 'Forearm', 'Neck']);
});

it('ne coupe qu’après le regroupement : six lignes, pas six parties', function (): void {
    $user = User::factory()->create();
    mensurationsDeCorps($user, array_fill_keys(['Waist', 'Chest', 'Hips', 'Thigh L', 'Thigh R', 'Biceps L', 'Biceps R', 'Calf L', 'Calf R', 'Neck'], [40]));

    expect(nomsDesLignesDeCorps(lignesDeCorps($user)))->toBe(['Waist', 'Chest', 'Hips', 'Thigh', 'Biceps', 'Calf']);
});

it('ne rend qu’une pastille quand un seul côté est saisi', function (): void {
    $user = User::factory()->create();
    mensurationsDeCorps($user, ['Calf R' => [39.5]]);

    $lignes = lignesDeCorps($user);

    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]['famille'])->toBe('Calf')
        ->and($lignes[0]['cotes'])->toHaveCount(1)
        ->and($lignes[0]['cotes'][0]['part'])->toBe('Calf R')
        ->and($lignes[0]['cotes'][0]['cote'])->toBe('droite');
});

it('garde l’unité, la date et l’écart de chaque côté', function (): void {
    $user = User::factory()->create();
    mensurationsDeCorps($user, [
        'Thigh L' => [60, 'cm', '2026-03-03'],
        'Thigh R' => [23.5, 'in', '2026-10-01'],
    ]);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Thigh R', 'value' => 24, 'unit' => 'in', 'measured_at' => '2026-11-12']);

    $cotes = lignesDeCorps($user)[0]['cotes'];

    expect($cotes[0])->toMatchArray(['unit' => 'cm', 'date' => '2026-03-03', 'current' => 60.0, 'diff' => 0.0])
        ->and($cotes[1])->toMatchArray(['unit' => 'in', 'date' => '2026-11-12', 'current' => 24.0, 'diff' => 0.5]);
});

it('ne mêle jamais une partie nommée « Biceps » ni une saisie libre à une famille', function (): void {
    $user = User::factory()->create();
    mensurationsDeCorps($user, [
        'Biceps' => [38],
        'Biceps L' => [37],
        'Cuisse gauche' => [60],
        'Poignet gauche' => [17],
    ]);

    $lignes = lignesDeCorps($user);
    $simple = collect($lignes)->firstWhere('part', 'Biceps');
    $famille = collect($lignes)->firstWhere('famille', 'Biceps');
    $libre = collect($lignes)->firstWhere('part', 'Cuisse gauche');

    expect($lignes)->toHaveCount(4)
        ->and($simple)->not->toHaveKey('cotes')
        ->and($famille['cotes'])->toHaveCount(1)
        ->and($libre)->toMatchArray(['label' => 'Cuisse gauche', 'current' => 60.0])
        ->and(nomsDesLignesDeCorps($lignes))->toContain('Poignet gauche');
});
