<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
 * Un objectif sur une partie du corps suit les mensurations qu'on saisit et
 * qu'on supprime (#1954).
 *
 * Depuis #1454, un objectif peut viser le tour de taille, lu dans
 * `body_part_measurements`. Seules les pesées relançaient pourtant le recalcul :
 * saisir 79 cm de tour de taille laissait l'objectif « descendre à 80 » à 90,
 * 0 %, jusqu'à la prochaine série ou pesée. Ces tests passent par les routes de
 * la page des mensurations ; la file est synchrone dans les tests.
 *
 * Le poids de corps et la masse grasse, colonnes de `body_measurements`, suivent
 * la même règle depuis #2107. L'objectif lisait la dernière pesée, qu'elle porte
 * ou non sa colonne, et gardait sa valeur quand elle n'en portait pas : une
 * pesée supprimée, ou une pesée sans masse grasse, le laissaient atteint sur la
 * valeur d'une mesure qui n'existait plus.
 */

beforeEach(function (): void {
    // La saisie refuse une date future : l'horloge est arrêtée, pour que les
    // dates écrites ici restent dans le passé quel que soit le jour du test.
    Carbon::setTestNow('2026-06-20 09:00:00');
});

/**
 * Un objectif « descendre de 90 à 80 cm » sur le tour de taille.
 */
function mensurationSaisieObjectifDeTourDeTaille(User $user): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'Waist',
        'exercise_id' => null,
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 80,
        'completed_at' => null,
    ]);
}

/**
 * Saisit un tour de taille par la route, comme la page des mensurations.
 */
function mensurationSaisieDuTourDeTaille(User $user, float $valeur, string $le): BodyPartMeasurement
{
    actingAs($user)
        ->post(route('body-parts.store'), [
            'part' => 'Waist',
            'value' => $valeur,
            'unit' => 'cm',
            'measured_at' => $le,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return BodyPartMeasurement::query()
        ->where('user_id', $user->id)
        ->latest('id')
        ->firstOrFail();
}

it('fait avancer l’objectif dès la saisie de la mensuration', function (): void {
    $user = User::factory()->create();
    $objectif = mensurationSaisieObjectifDeTourDeTaille($user);

    mensurationSaisieDuTourDeTaille($user, 85, '2026-06-10');

    $objectif->refresh();

    expect($objectif->current_value)->toBe(85.0)
        ->and($objectif->progress_pct)->toBe(50.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('marque l’objectif atteint quand la mensuration saisie franchit la cible', function (): void {
    $user = User::factory()->create();
    $objectif = mensurationSaisieObjectifDeTourDeTaille($user);

    mensurationSaisieDuTourDeTaille($user, 79, '2026-06-15');

    $objectif->refresh();

    expect($objectif->current_value)->toBe(79.0)
        ->and($objectif->progress_pct)->toBe(100.0)
        ->and($objectif->completed_at)->not->toBeNull();
});

it('rouvre l’objectif quand on supprime la mensuration qui l’avait atteint', function (): void {
    $user = User::factory()->create();
    $objectif = mensurationSaisieObjectifDeTourDeTaille($user);

    mensurationSaisieDuTourDeTaille($user, 85, '2026-06-10');
    $atteinte = mensurationSaisieDuTourDeTaille($user, 79, '2026-06-15');

    expect($objectif->refresh()->completed_at)->not->toBeNull();

    actingAs($user)
        ->delete(route('body-parts.destroy', $atteinte))
        ->assertRedirect();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(85.0)
        ->and($objectif->progress_pct)->toBe(50.0)
        ->and($objectif->completed_at)->toBeNull();
});

/*
 * Plus aucune mesure de cette partie : l'objectif revient à sa valeur de
 * départ, celle d'avant toute mesure, et non à la dernière valeur lue.
 */
it('ramène l’objectif à sa valeur de départ quand on supprime sa seule mensuration', function (): void {
    $user = User::factory()->create();
    $objectif = mensurationSaisieObjectifDeTourDeTaille($user);

    $seule = mensurationSaisieDuTourDeTaille($user, 79, '2026-06-15');

    expect($objectif->refresh()->completed_at)->not->toBeNull();

    actingAs($user)
        ->delete(route('body-parts.destroy', $seule))
        ->assertRedirect();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(90.0)
        ->and($objectif->progress_pct)->toBe(0.0)
        ->and($objectif->completed_at)->toBeNull();
});

/**
 * Un objectif sur une colonne des pesées : le poids de corps (`weight`) ou la
 * masse grasse (`body_fat`).
 */
function mensurationSaisieObjectifDePesee(User $user, string $colonne, float $depart, float $cible): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => $colonne,
        'exercise_id' => null,
        'start_value' => $depart,
        'current_value' => $depart,
        'target_value' => $cible,
        'completed_at' => null,
    ]);
}

/**
 * Saisit une pesée par la route, comme la page des mensurations ; la masse
 * grasse y est facultative (`BodyMeasurementStoreRequest`).
 */
function mensurationSaisieDeLaPesee(User $user, float $poids, ?float $masseGrasse, string $le): BodyMeasurement
{
    actingAs($user)
        ->post(route('body-measurements.store'), [
            'weight' => $poids,
            'body_fat' => $masseGrasse,
            'measured_at' => $le,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return BodyMeasurement::query()
        ->where('user_id', $user->id)
        ->latest('id')
        ->firstOrFail();
}

/*
 * « Descendre à 75 kg » : une pesée de 74 saisie par erreur, puis supprimée.
 * Plus aucune pesée ne soutient l'objectif, qui revient à sa valeur de départ
 * au lieu de rester atteint, à 74, pour de bon (#2107).
 */
it('ramène l’objectif de poids de corps à sa valeur de départ quand on supprime sa seule pesée', function (): void {
    $user = User::factory()->create();
    $objectif = mensurationSaisieObjectifDePesee($user, 'weight', depart: 80, cible: 75);

    $seule = mensurationSaisieDeLaPesee($user, 74, null, '2026-06-15');

    $objectif->refresh();

    expect($objectif->current_value)->toBe(74.0)
        ->and($objectif->progress_pct)->toBe(100.0)
        ->and($objectif->completed_at)->not->toBeNull();

    actingAs($user)
        ->delete(route('body-measurements.destroy', $seule))
        ->assertRedirect();

    $objectif->refresh();

    expect($user->bodyMeasurements()->count())->toBe(0)
        ->and($objectif->current_value)->toBe(80.0)
        ->and($objectif->progress_pct)->toBe(0.0)
        ->and($objectif->completed_at)->toBeNull();
});

/*
 * « Descendre à 15 % » : 19 %, puis 14 %, puis une pesée sans masse grasse. La
 * pesée sans masse grasse ne dit rien de la masse grasse, et supprimer la mesure
 * à 14 % ramène l'objectif à la seule masse grasse qui reste, 19 % (#2107).
 */
it('suit la dernière pesée qui porte une masse grasse, quelles que soient les pesées sans masse grasse qui la suivent', function (): void {
    $user = User::factory()->create();
    $objectif = mensurationSaisieObjectifDePesee($user, 'body_fat', depart: 20, cible: 15);

    mensurationSaisieDeLaPesee($user, 82, 19, '2026-06-10');
    $atteinte = mensurationSaisieDeLaPesee($user, 81, 14, '2026-06-15');
    mensurationSaisieDeLaPesee($user, 80, null, '2026-06-18');

    $objectif->refresh();

    expect($objectif->current_value)->toBe(14.0)
        ->and($objectif->completed_at)->not->toBeNull();

    actingAs($user)
        ->delete(route('body-measurements.destroy', $atteinte))
        ->assertRedirect();

    $objectif->refresh();

    expect($user->bodyMeasurements()->orderBy('measured_at')->pluck('body_fat')->all())->toBe(['19.00', null])
        ->and($objectif->current_value)->toBe(19.0)
        ->and($objectif->progress_pct)->toBe(20.0)
        ->and($objectif->completed_at)->toBeNull();
});
