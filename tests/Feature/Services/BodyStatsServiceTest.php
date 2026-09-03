<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\User;
use App\Services\Stats\BodyStatsService;

/*
 * `getLatestBodyMetrics` n'avait aucun test, et huit mutants y survivaient : la
 * taille de la fenetre (`take(2)`), le decalage vers la mesure precedente
 * (`skip(1)`), le sens de la soustraction, la precision de l'arrondi, et la
 * valeur de repli quand il n'y a qu'une mesure (#1446).
 *
 * Le chiffre concerne est la variation de poids affichee sur le tableau de bord.
 * Une soustraction inversee y ecrirait 159,8 kg de variation ; un `skip(0)`
 * ecrirait 0 ; une precision changee ecrirait 0,75 ou 1. Aucun de ces cas
 * n'aurait fait echouer quoi que ce soit.
 *
 * Les valeurs ne sont pas choisies au hasard : 79,50 puis 80,25 donnent un ecart
 * de 0,75, que `round(..., 1)` rend 0,8 — la ou une precision de 2 laisserait
 * 0,75 et une precision de 0 donnerait 1. Un ecart comme 81,40 -> 80,90 vaudrait
 * 0,5 aux trois precisions et ne separerait rien.
 *
 * `measured_at` est une colonne DATE : deux mesures le meme jour seraient a
 * egalite et l'ordre deviendrait indetermine. D'ou des jours distincts.
 */

it('calcule la variation de poids entre les deux dernières mesures', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 79.50,
        'body_fat' => 18.0,
        'measured_at' => '2026-03-09',
    ]);

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 80.25,
        'body_fat' => 18.4,
        'measured_at' => '2026-03-11',
    ]);

    $metrics = app(BodyStatsService::class)->getLatestBodyMetrics($user);

    expect((float) $metrics->latest_weight)->toBe(80.25)
        ->and((float) $metrics->latest_body_fat)->toBe(18.4)
        // 80,25 - 79,50 = 0,75, arrondi a 0,8. Une soustraction inversee
        // donnerait 159,8, un skip(0) donnerait 0,0.
        ->and($metrics->weight_change)->toBe(0.8);
});

it('n’annonce aucune variation quand il n’y a qu’une mesure', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 80.0,
        'body_fat' => 18.0,
        'measured_at' => '2026-03-11',
    ]);

    $metrics = app(BodyStatsService::class)->getLatestBodyMetrics($user);

    // Zero, et non -1 ou 1 : il n'y a rien a comparer, pas une variation d'un
    // kilo dans un sens ou dans l'autre.
    expect($metrics->weight_change)->toBe(0.0)
        ->and((float) $metrics->latest_weight)->toBe(80.0);
});

it('ne regarde que les deux dernières mesures, pas les plus anciennes', function (): void {
    $user = User::factory()->create();

    foreach ([['2026-03-01', 90.0], ['2026-03-09', 79.50], ['2026-03-11', 80.25]] as [$date, $poids]) {
        BodyMeasurement::factory()->create([
            'user_id' => $user->id,
            'weight' => $poids,
            'body_fat' => 18.0,
            'measured_at' => $date,
        ]);
    }

    // Toujours 0,8 : la mesure a 90 kg est plus ancienne et ne doit pas entrer
    // dans le calcul. Une fenetre elargie a trois donnerait un autre chiffre.
    expect(app(BodyStatsService::class)->getLatestBodyMetrics($user)->weight_change)->toBe(0.8);
});

it('ne renvoie rien quand aucune mesure n’existe', function (): void {
    $metrics = app(BodyStatsService::class)->getLatestBodyMetrics(User::factory()->create());

    expect($metrics->latest_weight)->toBeNull()
        ->and($metrics->latest_body_fat)->toBeNull()
        ->and($metrics->weight_change)->toBe(0.0);
});
