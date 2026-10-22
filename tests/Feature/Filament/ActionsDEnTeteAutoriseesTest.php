<?php

declare(strict_types=1);

use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\TachesPlanifiees\Pages\ListTachesPlanifiees;
use App\Models\TachePlanifiee;
use Database\Seeders\PrecorExerciseSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/*
 * Les actions d'en-tête écrites pour le panneau exigent la capacité de ce
 * qu'elles font : « Installer Exercices par Défaut » écrit dans le catalogue
 * commun, et demande donc celle de créer un exercice (`Create:Exercise`) ;
 * « Relire le planning » réécrit la liste des tâches surveillées, et demande
 * `sync-planning`, ouverte au super administrateur par la liste des
 * capacités. Sans elle, le bouton est caché, et un appel forgé, qui monte
 * l'action par son nom comme une requête Livewire écrite à la main, ne fait
 * rien.
 */

it('cache « Installer Exercices par Défaut » à qui ne peut pas créer d’exercice, et un appel forgé ne lance rien', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise', 'View:Exercise', 'Update:Exercise', 'Delete:Exercise']), 'admin');
    $catalogue = $this->spy(PrecorExerciseSeeder::class);

    Livewire::test(ListExercises::class)
        ->assertActionHidden('installerParDefaut')
        ->call('mountAction', 'installerParDefaut')
        ->call('callMountedAction');

    $catalogue->shouldNotHaveReceived('run');
});

it('installe les exercices par défaut pour qui peut créer un exercice', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise', 'Create:Exercise']), 'admin');
    $catalogue = $this->spy(PrecorExerciseSeeder::class);

    Livewire::test(ListExercises::class)
        ->assertActionVisible('installerParDefaut')
        ->callAction('installerParDefaut')
        ->assertNotified('Exercices installés avec succès !');

    $catalogue->shouldHaveReceived('run')->once();
});

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
