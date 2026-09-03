<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Http\Controllers\GoalController;
use App\Models\BodyPartMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;

/*
 * `weight` et `body_fat` sont des COLONNES de `body_measurements` ; une partie
 * du corps est une LIGNE de `body_part_measurements`, designee par son nom.
 * Lire la seconde comme la premiere rendait « Unknown column 'waist' » — une
 * erreur 500 a chaque pesee enregistree (#1454).
 */
function objectifSurUnePartieDuCorps(User $proprietaire, string $type, float $depart, float $cible): Goal
{
    return Goal::factory()->create([
        'user_id' => $proprietaire->id,
        'type' => GoalType::Measurement,
        'measurement_type' => $type,
        'start_value' => $depart,
        'target_value' => $cible,
        'current_value' => $depart,
        'completed_at' => null,
    ]);
}

function mesureDUnePartieDuCorps(User $proprietaire, string $partie, float $valeur, string $unite, string $le): BodyPartMeasurement
{
    return BodyPartMeasurement::factory()->create([
        'user_id' => $proprietaire->id,
        'part' => $partie,
        'value' => $valeur,
        'unit' => $unite,
        'measured_at' => $le,
    ]);
}

it('suit le tour de taille', function (): void {
    $proprietaire = User::factory()->create();
    $objectif = objectifSurUnePartieDuCorps($proprietaire, 'Waist', 90.0, 80.0);

    mesureDUnePartieDuCorps($proprietaire, 'Waist', 88.0, 'cm', '2026-06-01');
    mesureDUnePartieDuCorps($proprietaire, 'Waist', 85.0, 'cm', '2026-06-15');

    app(GoalService::class)->updateGoalProgress($objectif);

    expect($objectif->current_value)->toBe(85.0);
});

/*
 * Les objectifs crees avant que les options ne soient retirees portent
 * « waist » en minuscules. La collation `utf8mb4_unicode_ci` de `part` les
 * rapproche sans `lower()`, qui aurait ecarte l'index.
 */
it('retrouve les mesures quelle que soit la casse', function (): void {
    $proprietaire = User::factory()->create();
    $objectif = objectifSurUnePartieDuCorps($proprietaire, 'waist', 90.0, 80.0);

    mesureDUnePartieDuCorps($proprietaire, 'Waist', 84.0, 'cm', '2026-06-15');

    app(GoalService::class)->updateGoalProgress($objectif);

    expect($objectif->current_value)->toBe(84.0);
});

it('ramene les pouces en centimetres', function (): void {
    $proprietaire = User::factory()->create();
    $objectif = objectifSurUnePartieDuCorps($proprietaire, 'Waist', 90.0, 80.0);

    // 32 pouces, soit 81,28 cm. Sans conversion, l'objectif « descendre a 80 »
    // serait atteint par une mesure de 80 pouces — deux metres de tour de
    // taille.
    mesureDUnePartieDuCorps($proprietaire, 'Waist', 32.0, 'in', '2026-06-15');

    app(GoalService::class)->updateGoalProgress($objectif);

    expect($objectif->current_value)->toBe(81.28);
});

it('sait qu un tour de taille se vise a la baisse', function (): void {
    $proprietaire = User::factory()->create();
    $objectif = objectifSurUnePartieDuCorps($proprietaire, 'Waist', 90.0, 80.0);

    mesureDUnePartieDuCorps($proprietaire, 'Waist', 79.0, 'cm', '2026-06-15');

    app(GoalService::class)->updateGoalProgress($objectif);

    expect($objectif->completed_at)->not->toBeNull();
});

it('laisse sans progression une mensuration que personne ne mesure', function (): void {
    $proprietaire = User::factory()->create();
    $objectif = objectifSurUnePartieDuCorps($proprietaire, 'Waist', 90.0, 80.0);

    mesureDUnePartieDuCorps($proprietaire, 'Chest', 100.0, 'cm', '2026-06-15');

    app(GoalService::class)->updateGoalProgress($objectif);

    // La valeur de depart, pas celle d'une AUTRE partie du corps.
    expect($objectif->current_value)->toBe(90.0);
});

it('ne lit qu une seule mesure, quelle que soit la profondeur de l historique', function (): void {
    $proprietaire = User::factory()->create();
    $objectif = objectifSurUnePartieDuCorps($proprietaire, 'Waist', 90.0, 80.0);

    foreach (range(1, 40) as $jour) {
        mesureDUnePartieDuCorps($proprietaire, 'Waist', 90.0 - ($jour / 10), 'cm', '2026-05-'.str_pad((string) ($jour % 28 + 1), 2, '0', STR_PAD_LEFT));
    }

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(GoalService::class)->updateGoalProgress($objectif);
    $surLesParties = collect(\Illuminate\Support\Facades\DB::getQueryLog())
        ->filter(fn (array $r): bool => str_contains((string) $r['query'], 'body_part_measurements'));
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect($surLesParties)->toHaveCount(1)
        ->and($surLesParties->pluck('query')->implode(' '))->toContain('limit 1');
});

it('propose les parties du corps, et les borne des deux cotes', function (): void {
    $valeurs = GoalController::measurementTypeValues();

    expect($valeurs)->toContain('weight', 'body_fat', 'Waist', 'Chest', 'Biceps L')
        ->and($valeurs)->toEqualCanonicalizing(
            array_merge(['weight', 'body_fat'], BodyPartMeasurement::COMMON_PARTS)
        );
});

it('annonce des kilogrammes pour un objectif de poids de corps', function (): void {
    $proprietaire = User::factory()->create();

    // Le ternaire ne connaissait que la masse grasse : tout le reste, y compris
    // le poids de corps, s'affichait en centimetres.
    expect(objectifSurUnePartieDuCorps($proprietaire, 'weight', 80.0, 75.0)->unit)->toBe('kg')
        ->and(objectifSurUnePartieDuCorps($proprietaire, 'body_fat', 20.0, 15.0)->unit)->toBe('%')
        ->and(objectifSurUnePartieDuCorps($proprietaire, 'Waist', 90.0, 80.0)->unit)->toBe('cm');
});
