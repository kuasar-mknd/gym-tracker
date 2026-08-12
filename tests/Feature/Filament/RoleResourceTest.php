<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Role')), 'admin');
});

it('liste les rôles existants avec leur nom et leur guard', function (): void {
    $editor = Role::create(['name' => 'editeur_contenu', 'guard_name' => 'admin']);
    $support = Role::create(['name' => 'support_client', 'guard_name' => 'admin']);

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords([$editor, $support])
        ->assertTableColumnStateSet('name', 'editeur_contenu', $editor)
        ->assertTableColumnStateSet('guard_name', 'admin', $editor)
        // la colonne name est passée à Str::headline avant affichage
        ->assertSee('Editeur Contenu');
});

it('compte les permissions attachées à chaque rôle', function (): void {
    $role = Role::create(['name' => 'role_a_trois_permissions', 'guard_name' => 'admin']);
    foreach (['ViewAny:Workout', 'View:Workout', 'Update:Workout'] as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'admin'));
    }
    $empty = Role::create(['name' => 'role_vide', 'guard_name' => 'admin']);

    Livewire::test(ListRoles::class)
        ->assertTableColumnStateSet('permissions_count', 3, $role)
        ->assertTableColumnStateSet('permissions_count', 0, $empty);
});

it('crée un rôle sur le guard admin depuis le formulaire', function (): void {
    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'moderateur', 'guard_name' => 'admin'])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Role::query()->where('name', 'moderateur')->sole();

    expect($created->guard_name)->toBe('admin');
});

it('refuse un rôle sans nom', function (): void {
    $before = Role::query()->count();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => null, 'guard_name' => 'admin'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Role::query()->count())->toBe($before);
});

it('refuse un rôle dont le nom est déjà pris sur le même guard', function (): void {
    Role::create(['name' => 'deja_pris', 'guard_name' => 'admin']);

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'deja_pris', 'guard_name' => 'admin'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    expect(Role::query()->where('name', 'deja_pris')->count())->toBe(1);
});

it('charge le rôle existant dans le formulaire et enregistre son renommage', function (): void {
    $role = Role::create(['name' => 'ancien_role', 'guard_name' => 'admin']);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('guard_name')
        ->assertFormSet(['name' => 'ancien_role', 'guard_name' => 'admin'])
        ->fillForm(['name' => 'role_renomme'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->name)->toBe('role_renomme');
});

it('interdit la gestion des rôles à un admin sans les permissions Role', function (): void {
    $role = Role::create(['name' => 'role_protege', 'guard_name' => 'admin']);

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse()
        ->and(RoleResource::canEdit($role))->toBeFalse()
        ->and(RoleResource::canDelete($role))->toBeFalse();
});
