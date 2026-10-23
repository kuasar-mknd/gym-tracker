<?php

declare(strict_types=1);

use App\Models\Admin;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FilamentAdminPanel;

/*
 * Le rôle du super administrateur ouvre, par son nom, les capacités de
 * `AppServiceProvider::ouvrirLesOutilsAuSuperAdministrateur()`. Il ne se
 * supprime pas, ni par l'action de sa ligne, ni par le bouton de sa page, ni
 * en groupe, même pour qui porte `Delete:Role` et `DeleteAny:Role` ; il ne se
 * modifie que par qui le porte (`RolePolicy`) ; il ne se renomme pas, et
 * aucun autre rôle ne prend son nom (`AppServiceProvider::garderLeNomDuRoleDuSuperAdministrateur()`).
 * Les appels forgés montent l'action par son nom, sans le bouton.
 */

/**
 * Le rôle du super administrateur, porté par un titulaire.
 *
 * @return array{0: Role, 1: Admin}
 */
function roleDuSuperAdministrateurPorte(): array
{
    $titulaire = Admin::factory()->create();
    $role = roleDuSuperAdministrateurRole(Admin::roleDuSuperAdministrateur());
    $titulaire->assignRole($role);

    return [$role, $titulaire];
}

/**
 * Un rôle de la garde du panneau.
 */
function roleDuSuperAdministrateurRole(string $nom): Role
{
    $role = Role::findOrCreate($nom, 'admin');
    assert($role instanceof Role);

    return $role;
}

/**
 * Une permission Shield de la garde du panneau.
 */
function roleDuSuperAdministrateurPermission(string $nom): Permission
{
    $permission = Permission::findOrCreate($nom, 'admin');
    assert($permission instanceof Permission);

    return $permission;
}

/**
 * Les capacités de la liste que le titulaire du rôle reçoit, lues pour un administrateur.
 *
 * @return array<string, bool>
 */
function roleDuSuperAdministrateurCapacitesDe(Admin $administrateur): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $relu = $administrateur->fresh();
    assert($relu instanceof Admin);

    return collect(['DeleteAny:User', 'download-backup', 'view-logs', 'sync-planning'])
        ->mapWithKeys(static fn (string $capacite): array => [$capacite => $relu->can($capacite)])
        ->all();
}

it('ne supprime pas le rôle du super administrateur par l’action de sa ligne, même avec Delete:Role et DeleteAny:Role', function (): void {
    [$role, $titulaire] = roleDuSuperAdministrateurPorte();
    $autre = roleDuSuperAdministrateurRole('moderateur');
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Delete:Role', 'DeleteAny:Role']), 'admin');

    Livewire::test(ListRoles::class)
        ->assertActionHidden(TestAction::make('delete')->table($role))
        ->assertActionVisible(TestAction::make('delete')->table($autre))
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $role->id])
        ->call('callMountedAction');
    Livewire::test(ListRoles::class)->callAction(TestAction::make('delete')->table($autre));

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue()
        ->and(Role::query()->whereKey($autre->getKey())->exists())->toBeFalse()
        ->and($titulaire->fresh()?->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('ne supprime pas le rôle du super administrateur en groupe, et supprime les autres lignes choisies', function (): void {
    [$role] = roleDuSuperAdministrateurPorte();
    $autre = roleDuSuperAdministrateurRole('moderateur');
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Delete:Role', 'DeleteAny:Role']), 'admin');

    Livewire::test(ListRoles::class)->callTableBulkAction('delete', new Collection([$role, $autre]));

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue()
        ->and(Role::query()->whereKey($autre->getKey())->exists())->toBeFalse();
});

it('ne laisse ni ouvrir ni supprimer le rôle du super administrateur par sa page à qui ne le porte pas', function (): void {
    [$role] = roleDuSuperAdministrateurPorte();
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Update:Role', 'Delete:Role', 'DeleteAny:Role']), 'admin');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])->assertForbidden();

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue();
});

it('ne supprime pas son propre rôle par sa page pour le super administrateur, qui le modifie toujours', function (): void {
    [$role, $titulaire] = roleDuSuperAdministrateurPorte();
    $titulaire->givePermissionTo(collect(['ViewAny:Role', 'View:Role', 'Update:Role', 'Delete:Role'])
        ->map(static fn (string $permission): Permission => roleDuSuperAdministrateurPermission($permission)));
    Filament::setCurrentPanel('admin');
    $this->actingAs($titulaire, 'admin');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertActionHidden(DeleteAction::class)
        ->call('mountAction', 'delete')
        ->call('callMountedAction')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Role::query()->whereKey($role->getKey())->value('name'))->toBe(Admin::roleDuSuperAdministrateur());
});

it('refuse de renommer le rôle du super administrateur, même à son titulaire', function (): void {
    [$role, $titulaire] = roleDuSuperAdministrateurPorte();
    $titulaire->givePermissionTo(collect(['ViewAny:Role', 'View:Role', 'Update:Role'])
        ->map(static fn (string $permission): Permission => roleDuSuperAdministrateurPermission($permission)));
    Filament::setCurrentPanel('admin');
    $this->actingAs($titulaire, 'admin');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'ancien'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect(Role::query()->whereKey($role->getKey())->value('name'))->toBe(Admin::roleDuSuperAdministrateur())
        ->and(roleDuSuperAdministrateurCapacitesDe($titulaire))->each->toBeTrue();
});

it('refuse le nom du super administrateur à un autre rôle, et ses capacités restent fermées', function (bool $leRoleExiste): void {
    $titulaire = null;

    if ($leRoleExiste) {
        [, $titulaire] = roleDuSuperAdministrateurPorte();
    }

    $administrateur = FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Update:Role']);
    $moderateur = roleDuSuperAdministrateurRole('moderateur');
    $administrateur->assignRole($moderateur);
    $this->actingAs($administrateur, 'admin');

    Livewire::test(EditRole::class, ['record' => $moderateur->getKey()])
        ->fillForm(['name' => Admin::roleDuSuperAdministrateur()])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect(Role::query()->whereKey($moderateur->getKey())->value('name'))->toBe('moderateur')
        ->and(roleDuSuperAdministrateurCapacitesDe($administrateur))->each->toBeFalse();

    if ($titulaire instanceof Admin) {
        expect(roleDuSuperAdministrateurCapacitesDe($titulaire))->each->toBeTrue();
    }
})->with([
    'le rôle existe' => [true],
    'le rôle n’existe pas encore' => [false],
]);

it('laisse renommer un autre rôle sous un autre nom', function (): void {
    $administrateur = FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Update:Role']);
    $moderateur = roleDuSuperAdministrateurRole('moderateur');
    $this->actingAs($administrateur, 'admin');

    Livewire::test(EditRole::class, ['record' => $moderateur->getKey()])
        ->fillForm(['name' => 'relecteur'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Role::query()->whereKey($moderateur->getKey())->value('name'))->toBe('relecteur');
});

it('crée un rôle du nom du super administrateur qui n’existe pas encore sans l’accorder à personne', function (): void {
    $administrateur = FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Create:Role']);
    $this->actingAs($administrateur, 'admin');

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => Admin::roleDuSuperAdministrateur(), 'guard_name' => 'admin'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Role::query()->where('name', Admin::roleDuSuperAdministrateur())->exists())->toBeTrue()
        ->and(roleDuSuperAdministrateurCapacitesDe($administrateur))->each->toBeFalse();
});
