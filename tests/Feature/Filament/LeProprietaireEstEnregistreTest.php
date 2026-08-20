<?php

declare(strict_types=1);

use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Workouts\Pages\CreateWorkout;
use App\Models\Exercise;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le chemin nominal que #1352 disait impossible à écrire.
 *
 * `user_id` est exposé au formulaire mais absent du `$fillable` de ces trois
 * modèles. Hors production, le mode strict faisait lever et rien n'était
 * enregistré — d'où l'impossibilité d'écrire ces tests. EN PRODUCTION, le champ
 * était ignoré en silence : l'exploitant voyait une notification de succès et
 * la ligne se retrouvait sans propriétaire.
 *
 * Les pages l'affectent désormais explicitement, sans élargir le `$fillable` —
 * qui vaut pour tous les chemins d'assignation en masse, y compris ceux qui
 * partent d'une requête utilisateur.
 */

it('enregistre le propriétaire choisi à la création d’un exercice', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Exercise')), 'admin');

    $proprietaire = User::factory()->create();

    Livewire::test(CreateExercise::class)
        ->fillForm([
            'name' => 'Soulevé de terre roumain',
            'type' => 'strength',
            'category' => 'Jambes',
            'default_rest_time' => 120,
            'user_id' => $proprietaire->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Exercise::class, [
        'name' => 'Soulevé de terre roumain',
        'user_id' => $proprietaire->getKey(),
    ]);
});

it('enregistre le changement de propriétaire à l’édition', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Exercise')), 'admin');

    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $avant->getKey()]);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['user_id' => $apres->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->refresh()->user_id)->toBe($apres->getKey());
});

it('enregistre le propriétaire choisi à la création d’un objectif', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');

    $proprietaire = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $proprietaire->getKey(),
            'title' => 'Descendre à 78 kg',
            'type' => 'measurement',
            'measurement_type' => 'weight',
            'start_value' => 82,
            'current_value' => 82,
            'target_value' => 78,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(\App\Models\Goal::class, [
        'title' => 'Descendre à 78 kg',
        'user_id' => $proprietaire->getKey(),
    ]);
});

it('enregistre le propriétaire choisi à la création d’une séance', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');

    $proprietaire = User::factory()->create();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => $proprietaire->getKey(),
            'name' => 'Séance du matin',
            'started_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(\App\Models\Workout::class, [
        'name' => 'Séance du matin',
        'user_id' => $proprietaire->getKey(),
    ]);
});
