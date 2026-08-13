<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

/**
 * WeightHistoryPoint et BodyFatHistoryPoint ne servent qu'à une chose :
 * traverser Inertia et arriver dans un graphique Chart.js. Le contrat n'est
 * donc pas la classe PHP, c'est le nom exact des clés du JSON. Renommer une
 * propriété publique ne casse aucun test de type, ne lève aucune erreur côté
 * serveur, et vide silencieusement la courbe côté navigateur :
 *
 *   WeightHistoryPoint  -> Components/Stats/WeightHistoryChart.vue lit .date et .weight
 *   BodyFatHistoryPoint -> Components/Stats/BodyFatChart.vue       lit .date et .body_fat
 *
 * On verrouille donc les clés sur le chemin réellement emprunté en production,
 * de la requête Inertia jusqu'au JSON que reçoit le navigateur.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-03-12 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
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
        $version = (string) actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->get(route('body-measurements.index'))
            ->headers->get('X-Inertia-Version');

        expect($version)->not->toBe('');

        $response = actingAs($user)
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
