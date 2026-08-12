<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Models\Exercise;
use App\Models\User;
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

it('interdit la gestion des exercices à un admin sans les permissions Exercise', function (): void {
    $exercise = Exercise::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(ExerciseResource::canViewAny())->toBeFalse()
        ->and(ExerciseResource::canCreate())->toBeFalse()
        ->and(ExerciseResource::canEdit($exercise))->toBeFalse()
        ->and(ExerciseResource::canDelete($exercise))->toBeFalse();
});
