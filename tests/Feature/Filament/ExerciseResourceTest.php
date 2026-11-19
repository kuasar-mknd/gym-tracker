<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Exercise')), 'admin');
});

it('liste les exercices avec leur type, leur catégorie et leur propriétaire', function (): void {
    $owner = User::factory()->create(['name' => 'Propriétaire Zoé']);
    $userExercise = Exercise::factory()->create([
        'name' => 'Squat bulgare',
        'type' => 'strength',
        'category' => 'Jambes',
        'default_rest_time' => 150,
        'user_id' => $owner->getKey(),
    ]);
    $systemExercise = Exercise::factory()->create([
        'name' => 'Tapis de course',
        'type' => 'cardio',
        'category' => 'Cardio',
        'user_id' => null,
    ]);

    Livewire::test(ListExercises::class)
        ->assertCanSeeTableRecords([$userExercise, $systemExercise])
        ->assertTableColumnStateSet('type', 'strength', $userExercise)
        ->assertTableColumnStateSet('type', 'cardio', $systemExercise)
        ->assertTableColumnStateSet('category', ExerciseCategory::Jambes, $userExercise)
        ->assertTableColumnStateSet('default_rest_time', 150, $userExercise)
        ->assertTableColumnStateSet('user.name', 'Propriétaire Zoé', $userExercise)
        ->assertTableColumnStateSet('user.name', null, $systemExercise);
});

it('filtre les exercices par nom via la recherche', function (): void {
    $wanted = Exercise::factory()->create(['name' => 'Développé militaire']);
    $unwanted = Exercise::factory()->create(['name' => 'Rowing barre']);

    Livewire::test(ListExercises::class)
        ->searchTable('Développé militaire')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('refuse un exercice sans nom et sans type', function (): void {
    $before = Exercise::query()->count();

    Livewire::test(CreateExercise::class)
        ->fillForm(['name' => null, 'type' => null])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'type' => 'required',
        ]);

    expect(Exercise::query()->count())->toBe($before);
});

it('refuse un type qui ne fait pas partie de la liste proposée', function (): void {
    Livewire::test(CreateExercise::class)
        ->fillForm(['name' => 'Exercice fantaisiste', 'type' => 'yoga'])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(Exercise::query()->where('name', 'Exercice fantaisiste')->exists())->toBeFalse();
});

it("charge l'exercice existant dans le formulaire d'édition", function (): void {
    $owner = User::factory()->create();
    $exercise = Exercise::factory()->create([
        'name' => 'Soulevé de terre',
        'type' => 'strength',
        'category' => 'Dos',
        'default_rest_time' => 210,
        'user_id' => $owner->getKey(),
    ]);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('type')
        ->assertFormFieldExists('category')
        ->assertFormFieldExists('default_rest_time')
        ->assertFormFieldExists('user_id')
        ->assertFormSet([
            'name' => 'Soulevé de terre',
            'type' => 'strength',
            'category' => 'Dos',
            'default_rest_time' => '210',
            'user_id' => (string) $owner->getKey(),
        ]);
});

/*
 * Un exercice du catalogue n'a pas de compte : `(int)` faisait du champ vide
 * le compte 0, que la clé étrangère refusait, et la création comme
 * l'enregistrement répondaient 500 (#2071).
 */
it('crée et modifie un exercice du catalogue, sans compte', function (): void {
    Livewire::test(CreateExercise::class)
        ->fillForm(['name' => 'Gainage', 'type' => 'timed', 'user_id' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    $exercise = Exercise::query()->where('name', 'Gainage')->sole();
    expect($exercise->user_id)->toBeNull();

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['name' => 'Gainage latéral', 'category' => ExerciseCategory::Abdominaux->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->fresh()?->only(['name', 'user_id', 'category']))
        ->toBe(['name' => 'Gainage latéral', 'user_id' => null, 'category' => ExerciseCategory::Abdominaux]);
});

it('interdit la gestion des exercices à un admin sans les permissions Exercise', function (): void {
    $exercise = Exercise::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(ExerciseResource::canViewAny())->toBeFalse()
        ->and(ExerciseResource::canCreate())->toBeFalse()
        ->and(ExerciseResource::canEdit($exercise))->toBeFalse()
        ->and(ExerciseResource::canDelete($exercise))->toBeFalse();
});

it('garde le compte d’un exercice visé et rend le champ inaccessible', function (): void {
    $proprietaire = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $proprietaire->id]);
    Goal::factory()->create(['user_id' => $proprietaire->id, 'exercise_id' => $exercice->id, 'type' => 'weight']);

    Livewire::test(EditExercise::class, ['record' => $exercice->getKey()])
        ->assertFormFieldDisabled('user_id')
        ->fillForm(['name' => 'Renommé'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercice->refresh()->user_id)->toBe($proprietaire->id)
        ->and($exercice->refresh()->name)->toBe('Renommé');
});

it('ignore un compte forgé pour un exercice visé, par la page comme par l’action de la table', function (): void {
    $proprietaire = User::factory()->create();
    $autre = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $proprietaire->id]);
    Goal::factory()->create(['user_id' => $proprietaire->id, 'exercise_id' => $exercice->id, 'type' => 'weight']);

    Livewire::test(EditExercise::class, ['record' => $exercice->getKey()])
        ->set('data.user_id', $autre->id)
        ->call('save');

    expect($exercice->refresh()->user_id)->toBe($proprietaire->id);

    Livewire::test(ListExercises::class)
        ->callAction(TestAction::make('edit')->table($exercice), ['name' => 'Forgé', 'type' => 'strength', 'user_id' => $autre->id]);

    expect($exercice->refresh()->user_id)->toBe($proprietaire->id);
});
