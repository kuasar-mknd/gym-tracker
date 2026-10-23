<?php

declare(strict_types=1);

use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Admin;
use App\Models\Goal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * La suppression groupée du panneau demande `DeleteAny:X`, que Shield ne
 * générait pas avant : la migration la donne à chaque rôle, et à chaque
 * administrateur en direct, qui tient `Delete:X`, sur la même garde. Un rôle
 * qui tient le jeu de permissions que Shield générait avant `deleteAny`
 * supprime alors en groupe ; un rôle qui ne supprime rien ne reçoit rien, et la
 * suppression groupée lui reste cachée. La migration se rejoue sans rien
 * écrire de plus. Elle n'écrit que des lignes (aucun ordre DDL) : la
 * transaction de `RefreshDatabase` les efface après chaque cas.
 */

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function suppressionGroupeeAccordeeMonter(): void
{
    $migration = require database_path('migrations/2026_10_23_064859_accorder_la_suppression_groupee_a_qui_supprime.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Le jeu de permissions que Shield générait pour un modèle avant `deleteAny`.
 *
 * @return list<string>
 */
function suppressionGroupeeAccordeeJeuDOrigine(string $modele): array
{
    return array_map(
        static fn (string $methode): string => ucfirst($methode).':'.$modele,
        ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder'],
    );
}

/**
 * Un rôle de la garde du panneau qui tient ces permissions, et un
 * administrateur qui le porte.
 *
 * @param  list<string>  $permissions
 */
function suppressionGroupeeAccordeeAdministrateur(string $role, array $permissions): Admin
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'admin');
    }

    $roleCree = Role::findOrCreate($role, 'admin');
    $roleCree->givePermissionTo($permissions);
    $administrateur = Admin::factory()->create();
    $administrateur->assignRole($roleCree);

    return $administrateur;
}

/**
 * Le nombre d'attributions d'une permission, par les rôles et en direct.
 *
 * @return array{roles: int, directs: int}
 */
function suppressionGroupeeAccordeeAttributions(string $permission): array
{
    $id = Permission::query()->where('name', $permission)->value('id');

    return [
        'roles' => DB::table('role_has_permissions')->where('permission_id', $id)->count(),
        'directs' => DB::table('model_has_permissions')->where('permission_id', $id)->count(),
    ];
}

it('donne la suppression groupée au rôle qui tient le jeu de permissions que Shield générait avant deleteAny', function (): void {
    $administrateur = suppressionGroupeeAccordeeAdministrateur('moderateur', suppressionGroupeeAccordeeJeuDOrigine('User'));
    $comptes = User::factory()->count(2)->create();

    suppressionGroupeeAccordeeMonter();
    Filament::setCurrentPanel('admin');
    $this->actingAs($administrateur, 'admin');

    Livewire::test(ListUsers::class)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', $comptes);

    expect(User::query()->whereKey($comptes->modelKeys())->count())->toBe(0)
        ->and(Permission::query()->where('name', 'DeleteAny:User')->value('guard_name'))->toBe('admin');
});

it('ne donne rien au rôle qui ne supprime rien, à qui la suppression groupée reste cachée', function (): void {
    $administrateur = suppressionGroupeeAccordeeAdministrateur('lecteur', ['ViewAny:User', 'View:User']);
    suppressionGroupeeAccordeeAdministrateur('moderateur', ['ViewAny:Goal', 'View:Goal', 'Delete:Goal']);

    suppressionGroupeeAccordeeMonter();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Filament::setCurrentPanel('admin');
    $this->actingAs($administrateur, 'admin');

    Livewire::test(ListUsers::class)->assertTableBulkActionHidden('delete');

    expect(Permission::query()->where('name', 'DeleteAny:User')->exists())->toBeFalse()
        ->and($administrateur->fresh()?->can('DeleteAny:Goal'))->toBeFalse();
});

it('donne la suppression groupée à l’administrateur qui tient la suppression en direct', function (): void {
    Permission::findOrCreate('ViewAny:Goal', 'admin');
    Permission::findOrCreate('Delete:Goal', 'admin');
    $administrateur = Admin::factory()->create();
    $administrateur->givePermissionTo(['ViewAny:Goal', 'Delete:Goal']);
    $objectifs = Goal::factory()->count(2)->create();

    suppressionGroupeeAccordeeMonter();
    Filament::setCurrentPanel('admin');
    $this->actingAs($administrateur, 'admin');

    Livewire::test(ListGoals::class)->callTableBulkAction('delete', $objectifs);

    expect(Goal::query()->whereKey($objectifs->modelKeys())->count())->toBe(0)
        ->and(suppressionGroupeeAccordeeAttributions('DeleteAny:Goal'))->toBe(['roles' => 0, 'directs' => 1]);
});

it('se rejoue sans rien écrire de plus', function (): void {
    suppressionGroupeeAccordeeAdministrateur('moderateur', ['ViewAny:User', 'Delete:User']);
    suppressionGroupeeAccordeeAdministrateur('relecteur', ['ViewAny:User', 'Delete:User']);

    suppressionGroupeeAccordeeMonter();
    $permissions = Permission::query()->count();
    suppressionGroupeeAccordeeMonter();

    expect(Permission::query()->count())->toBe($permissions)
        ->and(Permission::query()->where('name', 'DeleteAny:User')->count())->toBe(1)
        ->and(suppressionGroupeeAccordeeAttributions('DeleteAny:User'))->toBe(['roles' => 2, 'directs' => 0]);
});
