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
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
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

/*
 * Un objectif de mensuration suit une mensuration : sans elle, il ne suit
 * rien (#2132).
 *
 * `GoalStoreRequest` l'exige (`required_if:type,measurement`), mais le
 * panneau n'y passe pas : la mensuration y était facultative quel que soit le
 * type, et la valeur actuelle valait 0 par défaut. L'objectif « de 80 à 75 »
 * ainsi créé affichait une barre pleine au premier recalcul, et ne s'achevait
 * jamais. La création, la page de modification et l'action de la table
 * partagent le formulaire : les trois refusent.
 */
dataset('mensurations absentes d’un objectif du panneau', [
    'sans mensuration' => [null],
    'mensuration vide' => [''],
    'mensuration faite d’espaces' => ['   '],
]);

it('exige la mensuration d’un objectif de mensuration créé au panneau', function (?string $mensuration): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Descendre à 75',
            'type' => 'measurement',
            'measurement_type' => $mensuration,
            'target_value' => 75,
            'current_value' => 0,
            'start_value' => 80,
        ])
        ->call('create')
        ->assertHasFormErrors(['measurement_type' => 'required']);

    expect(Goal::query()->where('title', 'Descendre à 75')->exists())->toBeFalse();
})->with('mensurations absentes d’un objectif du panneau');

it('exige la mensuration d’un objectif de mensuration modifié au panneau, par la page et par la table', function (): void {
    $goal = Goal::factory()->create([
        'type' => 'measurement',
        'measurement_type' => null,
        'title' => 'Sans mensuration',
        'start_value' => 80,
        'current_value' => 0,
        'target_value' => 75,
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['title' => 'Toujours sans mensuration'])
        ->call('save')
        ->assertHasFormErrors(['measurement_type' => 'required']);

    Livewire::test(ListGoals::class)
        ->callAction(TestAction::make('edit')->table($goal), ['title' => 'Toujours sans mensuration'])
        ->assertHasFormErrors(['measurement_type' => 'required']);

    expect($goal->refresh()->title)->toBe('Sans mensuration');
});

/*
 * Le type se change dans le même formulaire : un objectif de fréquence passé
 * en mensuration doit en nommer une. Le type est `live()` : le champ se
 * marque obligatoire dès le choix, sans attendre l'envoi.
 */
it('exige la mensuration quand un objectif passe en mensuration au panneau', function (): void {
    $goal = Goal::factory()->create(['type' => 'frequency', 'measurement_type' => null]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->assertFormFieldExists('type', fn (Field $champ): bool => $champ->isLive())
        ->assertFormFieldExists('measurement_type', fn (Field $champ): bool => ! $champ->isRequired())
        ->fillForm(['type' => 'measurement'])
        ->assertFormFieldExists('measurement_type', fn (Field $champ): bool => $champ->isRequired())
        ->call('save')
        ->assertHasFormErrors(['measurement_type' => 'required']);

    expect($goal->refresh()->type)->toBe(GoalType::Frequency);
});

/*
 * L'exigence ne vaut que pour une mensuration : un objectif de fréquence n'en
 * nomme aucune, et un objectif de mensuration qui en nomme une s'enregistre.
 */
it('n’exige la mensuration que d’un objectif de mensuration', function (string $type, ?string $mensuration): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Objectif bien formé',
            'type' => $type,
            'measurement_type' => $mensuration,
            'target_value' => 75,
            'current_value' => 0,
            'start_value' => 80,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->where('title', 'Objectif bien formé')->sole()->measurement_type)->toBe($mensuration);
})->with([
    'fréquence, sans mensuration' => ['frequency', null],
    'mensuration, tour de taille' => ['measurement', 'Waist'],
    'mensuration, poids de corps' => ['measurement', 'weight'],
]);

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

/*
 * L'échéance est une colonne `DATE`, la date d'atteinte un horodatage : une
 * date hors de leur plage partait jusqu'à la base, ou jusqu'au formatage de
 * Filament pour l'an 10000, et l'enregistrement répondait 500 (#2071).
 */
it('refuse une date que la colonne de l’objectif ne tient pas', function (string $champ, string $date): void {
    $goal = Goal::factory()->create(['type' => 'frequency', 'completed_at' => null]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm([$champ => $date])
        ->call('save')
        ->assertHasFormErrors([$champ]);

    expect($goal->fresh()?->completed_at)->toBeNull();
})->with([
    'atteint après 2038' => ['completed_at', '2099-01-01 10:00:00'],
    'atteint avant 1970' => ['completed_at', '1960-01-01 10:00:00'],
    'échéance en l’an 10000' => ['deadline', '+10000-01-01'],
]);

it('interdit la gestion des objectifs à un admin sans les permissions Goal', function (): void {
    $goal = Goal::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(GoalResource::canViewAny())->toBeFalse()
        ->and(GoalResource::canCreate())->toBeFalse()
        ->and(GoalResource::canEdit($goal))->toBeFalse()
        ->and(GoalResource::canDelete($goal))->toBeFalse();
});
