<?php

declare(strict_types=1);

use App\Filament\Resources\Workouts\Pages\CreateWorkout;
use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Filament\Resources\Workouts\Pages\ListWorkouts;
use App\Filament\Resources\Workouts\WorkoutResource;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');
});

it('liste les séances avec leur propriétaire et leurs horaires', function (): void {
    $owner = User::factory()->create(['name' => 'Sportive Sophie']);
    $workout = Workout::factory()->create([
        'user_id' => $owner->getKey(),
        'name' => 'Séance du lundi',
        'started_at' => Carbon::parse('2026-03-02 18:00:00'),
        'ended_at' => Carbon::parse('2026-03-02 19:15:00'),
    ]);

    Livewire::test(ListWorkouts::class)
        ->assertCanSeeTableRecords([$workout])
        ->assertTableColumnStateSet('user.name', 'Sportive Sophie', $workout)
        ->assertTableColumnStateSet('name', 'Séance du lundi', $workout)
        ->assertTableColumnStateSet('started_at', Carbon::parse('2026-03-02 18:00:00'), $workout)
        ->assertTableColumnStateSet('ended_at', Carbon::parse('2026-03-02 19:15:00'), $workout);
});

it('trie les séances de la plus récente à la plus ancienne', function (): void {
    $oldest = Workout::factory()->create(['started_at' => Carbon::parse('2026-01-01 08:00:00')]);
    $newest = Workout::factory()->create(['started_at' => Carbon::parse('2026-06-01 08:00:00')]);
    $middle = Workout::factory()->create(['started_at' => Carbon::parse('2026-03-01 08:00:00')]);

    Livewire::test(ListWorkouts::class)
        ->sortTable('started_at', 'desc')
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

it('cherche une séance par le nom de son propriétaire', function (): void {
    $wantedOwner = User::factory()->create(['name' => 'Gaspard Cherché']);
    $wanted = Workout::factory()->create(['user_id' => $wantedOwner->getKey()]);
    $unwanted = Workout::factory()->create();

    Livewire::test(ListWorkouts::class)
        ->searchTable('Gaspard Cherché')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('refuse une séance sans utilisateur et sans date de début', function (): void {
    $before = Workout::query()->count();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => null,
            'name' => 'Séance orpheline',
            'started_at' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'user_id' => 'required',
            'started_at' => 'required',
        ]);

    expect(Workout::query()->count())->toBe($before);
});

it("refuse une date de début qui n'est pas une date", function (): void {
    $owner = User::factory()->create();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'name' => 'Séance mal datée',
            'started_at' => 'un jour de pluie',
        ])
        ->call('create')
        ->assertHasFormErrors(['started_at']);

    expect(Workout::query()->where('name', 'Séance mal datée')->exists())->toBeFalse();
});

it("charge la séance existante dans le formulaire d'édition", function (): void {
    $owner = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $owner->getKey(),
        'name' => 'Nom initial',
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
        'notes' => 'Bonne séance.',
    ]);

    Livewire::test(EditWorkout::class, ['record' => $workout->getKey()])
        ->assertFormFieldExists('user_id')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('started_at')
        ->assertFormFieldExists('ended_at')
        ->assertFormFieldExists('notes')
        ->assertFormSet([
            'user_id' => (string) $owner->getKey(),
            'name' => 'Nom initial',
            'started_at' => '2026-02-10 07:30:00',
            'ended_at' => '2026-02-10 08:45:00',
            'notes' => 'Bonne séance.',
        ]);
});

it('interdit la gestion des séances à un admin sans les permissions Workout', function (): void {
    $workout = Workout::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(WorkoutResource::canViewAny())->toBeFalse()
        ->and(WorkoutResource::canCreate())->toBeFalse()
        ->and(WorkoutResource::canEdit($workout))->toBeFalse()
        ->and(WorkoutResource::canDelete($workout))->toBeFalse();
});
