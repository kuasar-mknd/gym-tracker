<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le départ d'un objectif de mensuration créé au panneau : vide, il prend la
 * dernière mesure du compte, comme à l'application (#2338). Le champ forçait 0.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
});

/**
 * @param  array<string, mixed>  $surcharges
 */
function objectifDeMensurationDuPanneau(User $compte, array $surcharges = []): Goal
{
    return Goal::factory()->create([
        'user_id' => $compte->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'weight',
        'exercise_id' => null,
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 75,
        ...$surcharges,
    ]);
}

function peseeDuPanneau(User $compte, float $poids): void
{
    BodyMeasurement::factory()->create(['user_id' => $compte->id, 'weight' => $poids, 'measured_at' => now()->subDay()->toDateString()]);
}

it('ne pré-remplit plus le départ d’un objectif créé au panneau', function (): void {
    Livewire::test(CreateGoal::class)->assertFormSet(['start_value' => null]);
});

it('part de la dernière pesée quand l’objectif de mensuration est créé au panneau sans départ', function (): void {
    $compte = User::factory()->create();
    peseeDuPanneau($compte, 82);

    Livewire::test(CreateGoal::class)
        ->fillForm(['user_id' => $compte->id, 'title' => 'Descendre', 'type' => 'measurement', 'measurement_type' => 'weight', 'target_value' => 75, 'start_value' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    $objectif = Goal::query()->sole();

    expect($objectif->start_value)->toBe(82.0)
        ->and($objectif->completed_at)->toBeNull()
        ->and($objectif->progress_pct)->toBe(0.0);
});

it('garde un départ saisi au panneau', function (): void {
    $compte = User::factory()->create();
    peseeDuPanneau($compte, 82);

    Livewire::test(CreateGoal::class)
        ->fillForm(['user_id' => $compte->id, 'title' => 'Descendre', 'type' => 'measurement', 'measurement_type' => 'weight', 'target_value' => 75, 'start_value' => 90])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->sole()->start_value)->toBe(90.0);
});

it('laisse à 0 le départ vide d’une mensuration sans mesure', function (): void {
    $compte = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm(['user_id' => $compte->id, 'title' => 'Descendre', 'type' => 'measurement', 'measurement_type' => 'weight', 'target_value' => 75, 'start_value' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->sole()->start_value)->toBe(0.0);
});

it('ramène à 0 le départ vide d’un objectif qui n’est pas une mensuration, sans 500', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null, 'type' => 'strength']);

    Livewire::test(CreateGoal::class)
        ->fillForm(['user_id' => $compte->id, 'title' => 'Cent kilos', 'type' => 'weight', 'exercise_id' => $exercice->id, 'target_value' => 100, 'start_value' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->sole()->start_value)->toBe(0.0);
});

it('part de la dernière pesée quand la modification vide le départ, par la page et par la table', function (string $chemin): void {
    $compte = User::factory()->create();
    peseeDuPanneau($compte, 82);
    $objectif = objectifDeMensurationDuPanneau($compte);

    if ($chemin === 'page') {
        Livewire::test(EditGoal::class, ['record' => $objectif->getKey()])
            ->fillForm(['start_value' => null])
            ->call('save')
            ->assertHasNoFormErrors();
    } else {
        Livewire::test(ListGoals::class)
            ->callAction(TestAction::make('edit')->table($objectif), ['start_value' => null])
            ->assertHasNoFormErrors();
    }

    expect($objectif->refresh()->start_value)->toBe(82.0)
        ->and($objectif->completed_at)->toBeNull();
})->with([
    'par la page' => ['page'],
    'par l’action de la table' => ['table'],
]);

it('lit la mesure chez le compte choisi quand la modification change de compte', function (): void {
    $ancien = User::factory()->create();
    $nouveau = User::factory()->create();
    peseeDuPanneau($ancien, 82);
    peseeDuPanneau($nouveau, 70);
    $objectif = objectifDeMensurationDuPanneau($ancien, ['target_value' => 65]);

    Livewire::test(EditGoal::class, ['record' => $objectif->getKey()])
        ->fillForm(['user_id' => $nouveau->id, 'start_value' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($objectif->refresh()->start_value)->toBe(70.0);
});
