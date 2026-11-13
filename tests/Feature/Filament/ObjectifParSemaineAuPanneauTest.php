<?php

declare(strict_types=1);

use App\Enums\GoalPeriod;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le panneau ne passe par aucune `FormRequest` : l'objectif « N séances par
 * semaine » y porte les mêmes exigences que dans l'application (cible entière
 * de 1 à 14, départ à zéro), et la période ne survit pas à un changement de
 * type (`.ai/rules/filament.md`).
 */

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
});

it('crée au panneau un objectif par semaine, dont le départ vaut zéro', function (): void {
    $compte = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $compte->getKey(),
            'title' => 'Trois par semaine',
            'type' => 'frequency',
            'period' => 'week',
            'target_value' => 3,
        ])
        ->assertFormFieldHidden('start_value')
        ->call('create')
        ->assertHasNoFormErrors();

    $objectif = Goal::query()->where('title', 'Trois par semaine')->sole();

    expect($objectif->period)->toBe(GoalPeriod::Week)
        ->and($objectif->start_value)->toBe(0.0);
});

it('exige d’un objectif par semaine une cible entière de une à quatorze séances', function (mixed $cible, bool $accepte): void {
    $livewire = Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => User::factory()->create()->getKey(),
            'title' => 'Par semaine',
            'type' => 'frequency',
            'period' => 'week',
            'target_value' => $cible,
        ])
        ->call('create');

    $accepte ? $livewire->assertHasNoFormErrors() : $livewire->assertHasFormErrors(['target_value']);
})->with([
    'zéro' => [0, false],
    'une demi-séance' => [2.5, false],
    'quinze' => [15, false],
    'une' => [1, true],
    'quatorze' => [14, true],
]);

it('laisse une cible décimale à un objectif de fréquence au total', function (): void {
    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => User::factory()->create()->getKey(),
            'title' => 'Au total',
            'type' => 'frequency',
            'period' => 'total',
            'target_value' => 12.5,
            'start_value' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('ramène à « au total » la période d’un objectif qui quitte la fréquence', function (): void {
    $objectif = Goal::factory()->create([
        'type' => 'frequency',
        'period' => 'week',
        'target_value' => 3,
        'start_value' => 0,
    ]);

    Livewire::test(EditGoal::class, ['record' => $objectif->getKey()])
        ->fillForm([
            'type' => 'weight',
            'exercise_id' => Exercise::factory()->create()->getKey(),
            'target_value' => 100,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($objectif->refresh()->period)->toBe(GoalPeriod::Total);
});

it('montre la période au tableau', function (): void {
    $objectif = Goal::factory()->create(['type' => 'frequency', 'period' => 'week', 'target_value' => 3, 'start_value' => 0]);

    Livewire::test(ListGoals::class)->assertTableColumnStateSet('period', GoalPeriod::Week, $objectif);
});
