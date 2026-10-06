<?php

declare(strict_types=1);

use App\Enums\GoalType;
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
