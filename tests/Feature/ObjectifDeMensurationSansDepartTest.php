<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Enums\PersonalRecordType;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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

function ecritureDesObjectifsLue(): ?string
{
    foreach (DB::getQueryLog() as $entree) {
        $requete = (string) $entree['query'];

        if (str_starts_with($requete, 'update goals')) {
            return $requete;
        }
    }

    return null;
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

it('part de la première mesure d’une partie du corps, saisie en pouces, et la garde ensuite', function (): void {
    $user = User::factory()->create();
    $objectif = objectifDeMensurationSansMesure($user, 'Waist', 80);

    actingAs($user)
        ->post(route('body-parts.store'), ['part' => 'Waist', 'value' => 35, 'unit' => 'in', 'measured_at' => '2026-06-10'])
        ->assertSessionHasNoErrors();

    $objectif->refresh();

    expect($objectif->start_value)->toBe($objectif->current_value)
        ->and($objectif->start_value)->toBeGreaterThan(88)
        ->and($objectif->completed_at)->toBeNull();

    $depart = $objectif->start_value;

    actingAs($user)
        ->post(route('body-parts.store'), ['part' => 'Waist', 'value' => 84, 'unit' => 'cm', 'measured_at' => '2026-06-15'])
        ->assertSessionHasNoErrors();

    expect($objectif->refresh()->start_value)->toBe($depart)
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

it('ne reprend pas un départ donné, même quand la valeur stockée est retombée à 0', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 82, 'measured_at' => '2026-06-10']);
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'exercise_id' => null,
        'start_value' => 90,
        'target_value' => 75,
    ]);
    DB::table('goals')->where('id', $objectif->id)->update(['current_value' => 0, 'start_value' => 90]);

    $recalcule = app(GoalService::class)->objectifsRecalcules($user)->sole();

    expect($recalcule->current_value)->toBe(82.0)
        ->and($recalcule->start_value)->toBe(90.0);

    app(GoalService::class)->syncGoals($user);
    $objectif->refresh();

    expect($objectif->current_value)->toBe(82.0)
        ->and($objectif->start_value)->toBe(90.0);
});

it('ne réécrit pas un objectif déjà atteint à départ 0 quand on le renomme', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 41, 'measured_at' => '2026-06-10']);
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 41,
        'target_value' => 40,
        'completed_at' => '2026-06-10 10:00:00',
    ]);
    DB::table('goals')->where('id', $objectif->id)->update(['current_value' => 41, 'progress_pct' => 100, 'completed_at' => '2026-06-10 10:00:00']);

    actingAs($user)
        ->put(route('goals.update', $objectif), ['title' => 'Renommé', 'type' => 'measurement', 'measurement_type' => 'weight', 'target_value' => 40, 'start_value' => 0])
        ->assertSessionHasNoErrors();

    $objectif->refresh();

    expect($objectif->title)->toBe('Renommé')
        ->and($objectif->start_value)->toBe(0.0)
        ->and($objectif->current_value)->toBe(41.0)
        ->and($objectif->completed_at?->toDateString())->toBe('2026-06-10');
});

it('part de la première pesée quand on modifie un objectif sans mesure suivie, le 0 renvoyé tel quel', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $objectif = objectifDeMensurationSansMesure($user, 'weight');
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 82, 'measured_at' => '2026-06-10']);

    actingAs($user)
        ->put(route('goals.update', $objectif), ['title' => 'Renommé', 'type' => 'measurement', 'measurement_type' => 'weight', 'target_value' => 75, 'start_value' => 0])
        ->assertSessionHasNoErrors();

    $objectif->refresh();

    expect($objectif->start_value)->toBe(82.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('laisse à 0 le départ d’un objectif de charge qui prend un record', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null, 'type' => 'strength']);
    PersonalRecord::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercice->id, 'type' => PersonalRecordType::MaxWeight, 'value' => 80]);
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Weight,
        'measurement_type' => null,
        'exercise_id' => $exercice->id,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 100,
    ]);

    app(GoalService::class)->syncGoals($user);
    $objectif->refresh();

    expect($objectif->current_value)->toBe(80.0)
        ->and($objectif->start_value)->toBe(0.0);
});

it('part de la première pesée même quand le départ 0 est saisi, à la création par l’application', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 82, 'measured_at' => '2026-06-10']);

    actingAs($user)
        ->post(route('goals.store'), ['title' => 'Descendre', 'type' => 'measurement', 'measurement_type' => 'weight', 'target_value' => 75, 'start_value' => 0])
        ->assertSessionHasNoErrors();

    $objectif = Goal::query()->sole();

    expect($objectif->start_value)->toBe(82.0)
        ->and($objectif->completed_at)->toBeNull();
});

it('n’écrit le départ que des objectifs dont le recalcul l’a changé', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $objectif = objectifDeMensurationSansMesure($user, 'weight');
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 82, 'measured_at' => '2026-06-10']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(GoalService::class)->syncGoals($user);
    $premiere = ecritureDesObjectifsLue();

    // Une pesée de plus : la valeur change, le départ est déjà posé.
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80, 'measured_at' => '2026-06-15']);
    DB::flushQueryLog();
    app(GoalService::class)->syncGoals($user);
    $seconde = ecritureDesObjectifsLue();
    DB::disableQueryLog();

    expect($premiere)->toContain('start_value = case when id = ? and start_value = 0 then ? else start_value end')
        ->and($seconde)->not->toBeNull()
        ->and($seconde)->not->toContain('start_value')
        ->and($objectif->refresh()->start_value)->toBe(82.0);
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
