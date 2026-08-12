<?php

declare(strict_types=1);

use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\DailyVolumeTrendPoint;
use App\DTOs\Stats\WeightHistoryPoint;
use App\Models\BodyMeasurement;
use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\VolumeStatsService;
use Carbon\Carbon;

/**
 * Ces trois DTO ne servent qu'à une chose : traverser Inertia et arriver dans
 * un graphique Chart.js. Le contrat n'est donc pas la classe PHP, c'est le nom
 * exact des clés du JSON. Renommer une propriété publique ne casse aucun test
 * de type, ne lève aucune erreur côté serveur, et vide silencieusement la
 * courbe côté navigateur :
 *
 *   WeightHistoryPoint   -> Components/Stats/WeightHistoryChart.vue lit .date et .weight
 *   BodyFatHistoryPoint  -> Components/Stats/BodyFatChart.vue       lit .date et .body_fat
 *   DailyVolumeTrendPoint-> Components/Stats/VolumeTrendChart.vue   lit .volume (+ .date en label)
 *
 * On construit donc chaque point par son vrai producteur (le service Stats sur
 * des lignes réelles), puis on l'encode comme Inertia le fera.
 */

/**
 * Le point tel que le navigateur le reçoit, clés comprises.
 *
 * @return array<string, mixed>
 */
function encodedStatsPoint(object $point): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(json_encode($point, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-03-12 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('WeightHistoryPoint', function (): void {
    it('sort du service avec les clés que lit WeightHistoryChart.vue', function (): void {
        $user = User::factory()->create();
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 81.40,
            'measured_at' => '2026-03-09',
        ]);
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 80.90,
            'measured_at' => '2026-03-11',
        ]);
        // Hors de la fenêtre de 90 jours : ne doit pas apparaître.
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 95.00,
            'measured_at' => '2025-01-01',
        ]);

        $history = app(BodyStatsService::class)->getWeightHistory($user, 90);

        expect($history)->toHaveCount(2)
            ->and($history[0])->toBeInstanceOf(WeightHistoryPoint::class);

        expect(encodedStatsPoint($history[0]))->toBe([
            'date' => '09/03',
            'full_date' => '2026-03-09',
            'weight' => 81.4,
        ]);

        expect(encodedStatsPoint($history[1]))->toBe([
            'date' => '11/03',
            'full_date' => '2026-03-11',
            'weight' => 80.9,
        ]);
    });
});

describe('BodyFatHistoryPoint', function (): void {
    it('sort du service avec les clés que lit BodyFatChart.vue', function (): void {
        $user = User::factory()->create();
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 81.40,
            'body_fat' => 17.20,
            'measured_at' => '2026-03-09',
        ]);
        // Pesée sans mesure de masse grasse : absente de la courbe.
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 80.90,
            'body_fat' => null,
            'measured_at' => '2026-03-11',
        ]);

        $history = app(BodyStatsService::class)->getBodyFatHistory($user, 90);

        expect($history)->toHaveCount(1)
            ->and($history[0])->toBeInstanceOf(BodyFatHistoryPoint::class);

        expect(encodedStatsPoint($history[0]))->toBe([
            'date' => '09/03',
            'full_date' => '2026-03-09',
            'body_fat' => 17.2,
        ]);
    });
});

describe('DailyVolumeTrendPoint', function (): void {
    it('sort du service avec les clés que lit VolumeTrendChart.vue', function (): void {
        $user = User::factory()->create();

        // Deux séances le même jour : le point porte leur somme.
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => '2026-03-09 18:00:00',
            'workout_volume' => 1200.00,
        ]);
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => '2026-03-09 20:00:00',
            'workout_volume' => 300.50,
        ]);
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => '2026-03-12 07:00:00',
            'workout_volume' => 800.25,
        ]);
        // Antérieure à la fenêtre de 7 jours : ignorée.
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => '2026-03-01 09:00:00',
            'workout_volume' => 9999.00,
        ]);

        $trend = app(VolumeStatsService::class)->getDailyVolumeTrend($user, 7);

        expect($trend)->toHaveCount(7)
            ->and($trend[0])->toBeInstanceOf(DailyVolumeTrendPoint::class);

        // Les sept jours sont présents même sans séance : la courbe ne saute pas de jour.
        expect(array_map(fn (array $point): mixed => $point['date'], array_map(encodedStatsPoint(...), $trend)))
            ->toBe(['06/03', '07/03', '08/03', '09/03', '10/03', '11/03', '12/03']);

        expect(array_map(fn (DailyVolumeTrendPoint $point): float => $point->volume, $trend))
            ->toBe([0.0, 0.0, 0.0, 1500.5, 0.0, 0.0, 800.25]);

        // day_name est le libellé de l'axe : il est traduit, pas laissé en anglais.
        expect(array_map(fn (array $point): mixed => $point['day_name'], array_map(encodedStatsPoint(...), $trend)))
            ->toBe(['ven.', 'sam.', 'dim.', 'lun.', 'mar.', 'mer.', 'jeu.']);

        expect(encodedStatsPoint($trend[3]))->toBe([
            'date' => '09/03',
            'day_name' => 'lun.',
            'volume' => 1500.5,
        ]);
    });
});

/**
 * Le chemin réellement emprunté en production : BodyMeasurementController
 * expose getBodyProgressOverview() en prop différée « bodyStats », et
 * Pages/Measurements/Index.vue lit bodyStats.weightHistory / .bodyFatHistory.
 * Ce test verrouille toute la chaîne, y compris les deux clés du tableau.
 */
describe('la prop différée bodyStats de Measurements/Index', function (): void {
    it('livre les deux séries avec leurs clés de graphique', function (): void {
        $user = User::factory()->create();
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 81.40,
            'body_fat' => 17.20,
            'measured_at' => '2026-03-09',
        ]);
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => 80.90,
            'body_fat' => null,
            'measured_at' => '2026-03-11',
        ]);

        // La version d'asset est calculée pendant la requête (hash du manifest
        // Vite) : on la lit sur une première visite plutôt que de la deviner,
        // sinon Inertia répond 409 au rechargement partiel.
        $version = (string) $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->get(route('body-measurements.index'))
            ->headers->get('X-Inertia-Version');

        expect($version)->not->toBe('');

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'Measurements/Index',
                'X-Inertia-Partial-Data' => 'bodyStats',
            ])
            ->get(route('body-measurements.index'));

        $response->assertJsonPath('props.bodyStats.weightHistory', [
            ['date' => '09/03', 'full_date' => '2026-03-09', 'weight' => 81.4],
            ['date' => '11/03', 'full_date' => '2026-03-11', 'weight' => 80.9],
        ]);

        $response->assertJsonPath('props.bodyStats.bodyFatHistory', [
            ['date' => '09/03', 'full_date' => '2026-03-09', 'body_fat' => 17.2],
        ]);
    });
});
