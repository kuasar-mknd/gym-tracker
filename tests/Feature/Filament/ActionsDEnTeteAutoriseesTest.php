<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\TachesPlanifiees\Pages\ListTachesPlanifiees;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\TachePlanifiee;
use App\Models\User;
use App\Models\WorkoutTemplateLine;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/*
 * Les actions d'en-tête écrites pour le panneau exigent la capacité de ce
 * qu'elles font : « Installer Exercices par Défaut » crée, dans le catalogue
 * commun, les exercices qui y manquent, et demande donc celle de créer un
 * exercice (`Create:Exercise`) ; il ne modifie ni ne supprime rien, et un
 * exercice personnel du même nom qu'un exercice du catalogue ne l'empêche
 * pas de créer ce dernier, ce que le vrai catalogue (`PrecorExerciseSeeder`)
 * montre ici, avec le mode strict d'Eloquent comme hors production, et sans
 * lui comme en production.
 * « Relire le planning » réécrit la liste des tâches surveillées, et demande
 * `sync-planning`, ouverte au super administrateur par la liste des
 * capacités. Sans elle, le bouton est caché, et un appel forgé, qui monte
 * l'action par son nom comme une requête Livewire écrite à la main, ne fait
 * rien.
 */

/**
 * Le nombre d'exercices du catalogue commun.
 */
function actionsDEnTeteExercicesCommuns(): int
{
    return Exercise::query()->whereNull('user_id')->count();
}

it('cache « Installer Exercices par Défaut » à qui ne peut pas créer d’exercice, et un appel forgé ne lance rien', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise', 'View:Exercise', 'Update:Exercise', 'Delete:Exercise']), 'admin');

    Livewire::test(ListExercises::class)
        ->assertActionHidden('installerParDefaut')
        ->call('mountAction', 'installerParDefaut')
        ->call('callMountedAction');

    expect(actionsDEnTeteExercicesCommuns())->toBe(0);
});

it('installe le catalogue par défaut pour qui peut créer un exercice', function (bool $strict): void {
    Model::shouldBeStrict($strict);
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise', 'Create:Exercise']), 'admin');

    Livewire::test(ListExercises::class)
        ->assertActionVisible('installerParDefaut')
        ->callAction('installerParDefaut')
        ->assertNotified('Exercices installés avec succès !');
    Livewire::test(ListExercises::class)->callAction('installerParDefaut');

    expect(actionsDEnTeteExercicesCommuns())->toBe(24)
        ->and(Exercise::query()->whereNotNull('user_id')->count())->toBe(0)
        ->and(Exercise::query()->whereNull('user_id')->where('name', 'Leg Press')->first()?->category)->toBe(ExerciseCategory::Jambes);
})->with([
    'mode strict, hors production' => [true],
    'sans mode strict, en production' => [false],
]);

it('ne modifie ni ne supprime aucun exercice du catalogue en l’installant', function (bool $strict): void {
    Model::shouldBeStrict($strict);
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise', 'Create:Exercise']), 'admin');
    $ancien = Exercise::factory()->create(['name' => 'Precor Chest Press', 'user_id' => null, 'type' => 'strength', 'category' => 'Pectoraux']);
    $objectif = Goal::factory()->create(['exercise_id' => $ancien->id]);
    $ligneDeModele = WorkoutTemplateLine::factory()->create(['exercise_id' => $ancien->id]);
    $existant = Exercise::factory()->create(['name' => 'Chest Press', 'user_id' => null, 'type' => 'cardio', 'category' => 'Cardio']);

    Livewire::test(ListExercises::class)
        ->callAction('installerParDefaut')
        ->assertNotified('Exercices installés avec succès !');

    expect(Exercise::query()->whereKey($ancien->id)->exists())->toBeTrue()
        ->and(Goal::query()->whereKey($objectif->id)->exists())->toBeTrue()
        ->and(WorkoutTemplateLine::query()->whereKey($ligneDeModele->id)->exists())->toBeTrue()
        ->and($existant->fresh()?->only(['type', 'category']))->toBe(['type' => 'cardio', 'category' => ExerciseCategory::Cardio])
        ->and(Exercise::query()->whereNull('user_id')->where('name', 'Chest Press')->count())->toBe(1)
        ->and(actionsDEnTeteExercicesCommuns())->toBe(25);
})->with([
    'mode strict, hors production' => [true],
    'sans mode strict, en production' => [false],
]);

it('crée l’exercice commun du catalogue à côté d’un exercice personnel du même nom, sans toucher ce dernier', function (bool $strict): void {
    Model::shouldBeStrict($strict);
    $compte = User::factory()->create();
    $personnel = Exercise::factory()->create(['name' => 'Leg Press', 'user_id' => $compte->id, 'type' => 'cardio', 'category' => 'Cardio']);
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise', 'Create:Exercise']), 'admin');

    Livewire::test(ListExercises::class)
        ->callAction('installerParDefaut')
        ->assertNotified('Exercices installés avec succès !');

    expect(Exercise::query()->whereNull('user_id')->where('name', 'Leg Press')->count())->toBe(1)
        ->and(actionsDEnTeteExercicesCommuns())->toBe(24)
        ->and($personnel->fresh()?->only(['type', 'category', 'user_id']))->toBe(['type' => 'cardio', 'category' => ExerciseCategory::Cardio, 'user_id' => $compte->id]);
})->with([
    'mode strict, hors production' => [true],
    'sans mode strict, en production' => [false],
]);

it('cache « Relire le planning » à qui ne peut que lire les tâches planifiées, et un appel forgé ne relit rien', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:TachePlanifiee', 'View:TachePlanifiee']), 'admin');

    Livewire::test(ListTachesPlanifiees::class)
        ->assertActionHidden('synchroniser')
        ->call('mountAction', 'synchroniser')
        ->call('callMountedAction');

    expect(TachePlanifiee::query()->count())->toBe(0);
});

it('relit le planning pour le super administrateur, sans permission Shield', function (): void {
    $superAdministrateur = FilamentAdminPanel::admin();
    $superAdministrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $this->actingAs($superAdministrateur, 'admin');

    Livewire::test(ListTachesPlanifiees::class)->callAction('synchroniser');

    expect(TachePlanifiee::query()->where('name', 'app:remind-training')->exists())->toBeTrue();
});
