<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
 * Un objectif de mensuration créé avant toute mesure garde un départ de 0, et
 * la première mesure le disait atteint : « descendre à 75 » lu comme une montée
 * (#2337). Le départ devient cette première mesure, et il est ÉCRIT : le lot
 * (`syncGoals()`) ne persistait que la valeur, la barre et l'atteinte.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

function objectifDeMensurationSansMesure(User $user, string $mensuration, float $cible = 75): Goal
{
    actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Descendre',
            'type' => 'measurement',
            'measurement_type' => $mensuration,
            'target_value' => $cible,
        ])
        ->assertSessionHasNoErrors();

    return Goal::query()->where('user_id', $user->id)->sole();
}

function peseeParLaRoute(User $user, float $poids, string $le): void
{
    actingAs($user)
        ->post(route('body-measurements.store'), ['weight' => $poids, 'measured_at' => $le])
        ->assertSessionHasNoErrors();
}

it('part de la première pesée, et la garde aux pesées suivantes', function (): void {
    $user = User::factory()->create();
    $objectif = objectifDeMensurationSansMesure($user, 'weight');

    expect($objectif->start_value)->toBe(0.0);

    peseeParLaRoute($user, 82, '2026-06-10');
    $objectif->refresh();

    expect($objectif->start_value)->toBe(82.0)
        ->and($objectif->current_value)->toBe(82.0)
        ->and($objectif->progress_pct)->toBe(0.0)
        ->and($objectif->completed_at)->toBeNull();

    peseeParLaRoute($user, 80, '2026-06-15');
    $objectif->refresh();

    expect($objectif->start_value)->toBe(82.0)
        ->and($objectif->current_value)->toBe(80.0)
        ->and(round($objectif->progress_pct))->toBe(29.0)
        ->and($objectif->completed_at)->toBeNull();

    peseeParLaRoute($user, 74, '2026-06-18');
    $objectif->refresh();

    expect($objectif->start_value)->toBe(82.0)
        ->and($objectif->completed_at)->not->toBeNull();
});

it('part de la première mesure d’une partie du corps, en pouces comme en centimètres', function (): void {
    $user = User::factory()->create();
    $objectif = objectifDeMensurationSansMesure($user, 'Waist', 80);

    actingAs($user)
        ->post(route('body-parts.store'), ['part' => 'Waist', 'value' => 90, 'unit' => 'cm', 'measured_at' => '2026-06-10'])
        ->assertSessionHasNoErrors();

    $objectif->refresh();

    expect($objectif->start_value)->toBe(90.0)
        ->and($objectif->progress_pct)->toBe(0.0)
        ->and($objectif->completed_at)->toBeNull();

    actingAs($user)
        ->post(route('body-parts.store'), ['part' => 'Waist', 'value' => 34, 'unit' => 'in', 'measured_at' => '2026-06-15'])
        ->assertSessionHasNoErrors();

    $objectif->refresh();

    expect($objectif->start_value)->toBe(90.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('ne touche pas au départ d’un objectif déjà suivi, même à 0', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 41, 'measured_at' => '2026-06-10']);
    $atteint = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 41,
        'target_value' => 40,
        'completed_at' => '2026-06-10 10:00:00',
    ]);

    app(GoalService::class)->syncGoals($user);
    $atteint->refresh();

    expect($atteint->start_value)->toBe(0.0)
        ->and($atteint->completed_at)->not->toBeNull();
});

it('ne prend pas une mesure à zéro pour un départ', function (): void {
    $user = User::factory()->create();
    $objectif = objectifDeMensurationSansMesure($user, 'Waist', 80);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Waist', 'value' => 0, 'unit' => 'cm', 'measured_at' => '2026-06-10']);

    app(GoalService::class)->syncGoals($user);

    expect($objectif->refresh()->start_value)->toBe(0.0);
});

it('laisse le départ des autres types d’objectif', function (): void {
    $user = User::factory()->create();
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'measurement_type' => null,
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 5,
    ]);

    app(GoalService::class)->syncGoals($user);

    expect($objectif->refresh()->start_value)->toBe(0.0);
});

it('part de la première mesure aussi par le chemin d’un seul objectif', function (): void {
    $user = User::factory()->create();
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 75,
    ]);
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 82, 'measured_at' => '2026-06-10']);

    $lu = Goal::query()->findOrFail($objectif->id);
    app(GoalService::class)->updateGoalProgress($lu);

    expect($lu->start_value)->toBe(82.0)
        ->and($lu->completed_at)->toBeNull();
});
