<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
});

it('liste les objectifs avec leur propriétaire, leur type et leurs valeurs', function (): void {
    $owner = User::factory()->create(['name' => 'Objectif Owner']);
    $exercise = Exercise::factory()->create(['name' => 'Squat']);
    $goal = Goal::factory()->create([
        'user_id' => $owner->getKey(),
        'exercise_id' => $exercise->getKey(),
        'title' => 'Atteindre 100 kg au squat',
        'type' => 'weight',
        'target_value' => 100,
        'current_value' => 80,
        'start_value' => 60,
    ]);

    Livewire::test(ListGoals::class)
        ->assertCanSeeTableRecords([$goal])
        ->assertTableColumnStateSet('user.name', 'Objectif Owner', $goal)
        ->assertTableColumnStateSet('exercise.name', 'Squat', $goal)
        ->assertTableColumnStateSet('title', 'Atteindre 100 kg au squat', $goal)
        ->assertTableColumnStateSet('type', GoalType::Weight, $goal)
        ->assertTableColumnStateSet('target_value', 100.0, $goal)
        ->assertTableColumnStateSet('current_value', 80.0, $goal)
        ->assertTableColumnStateSet('start_value', 60.0, $goal);
});

it('trie les objectifs par valeur cible', function (): void {
    $small = Goal::factory()->create(['target_value' => 10]);
    $big = Goal::factory()->create(['target_value' => 900]);
    $mid = Goal::factory()->create(['target_value' => 400]);

    Livewire::test(ListGoals::class)
        ->sortTable('target_value', 'desc')
        ->assertCanSeeTableRecords([$big, $mid, $small], inOrder: true);
});

it('cherche un objectif par son titre', function (): void {
    $wanted = Goal::factory()->create(['title' => 'Perdre 5 kilos']);
    $unwanted = Goal::factory()->create(['title' => 'Courir un semi-marathon']);

    Livewire::test(ListGoals::class)
        ->searchTable('Perdre 5 kilos')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('refuse un objectif sans utilisateur, sans titre, sans type ni cible', function (): void {
    $before = Goal::query()->count();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => null,
            'title' => null,
            'type' => null,
            'target_value' => null,
            'current_value' => 0,
            'start_value' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'user_id' => 'required',
            'title' => 'required',
            'type' => 'required',
            'target_value' => 'required',
        ]);

    expect(Goal::query()->count())->toBe($before);
});

it("refuse un type d'objectif hors de la liste proposée", function (): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Objectif exotique',
            'type' => 'astrologie',
            'target_value' => 10,
            'current_value' => 0,
            'start_value' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(Goal::query()->where('title', 'Objectif exotique')->exists())->toBeFalse();
});

it("charge l'objectif existant dans le formulaire d'édition", function (): void {
    $owner = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $goal = Goal::factory()->create([
        'user_id' => $owner->getKey(),
        'exercise_id' => $exercise->getKey(),
        'title' => 'Titre initial',
        'type' => 'volume',
        'target_value' => 12000,
        'current_value' => 3000,
        'start_value' => 1000,
        'measurement_type' => 'tour_de_taille',
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->assertFormFieldExists('user_id')
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('type')
        ->assertFormFieldExists('exercise_id')
        ->assertFormFieldExists('measurement_type')
        ->assertFormFieldExists('deadline')
        ->assertFormSet([
            'user_id' => (string) $owner->getKey(),
            'exercise_id' => (string) $exercise->getKey(),
            'title' => 'Titre initial',
            'type' => 'volume',
            'measurement_type' => 'tour_de_taille',
        ]);
});

it('interdit la gestion des objectifs à un admin sans les permissions Goal', function (): void {
    $goal = Goal::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(GoalResource::canViewAny())->toBeFalse()
        ->and(GoalResource::canCreate())->toBeFalse()
        ->and(GoalResource::canEdit($goal))->toBeFalse()
        ->and(GoalResource::canDelete($goal))->toBeFalse();
});
