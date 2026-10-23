<?php

declare(strict_types=1);

use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Supplements\Pages\ListSupplements;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Workouts\Pages\ListWorkouts;
use App\Models\Achievement;
use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Supplement;
use App\Models\User;
use App\Models\Workout;
use BezhanSalleh\FilamentExceptions\Resources\ExceptionResource\Pages\ListExceptions;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FilamentAdminPanel;

/*
 * La suppression groupée du panneau (`DeleteBulkAction`), dans chaque table
 * qui la porte, celles des greffons comprises (rôles de Shield, exceptions
 * enregistrées) : elle ne s'offre qu'à qui porte la capacité `DeleteAny:X`
 * (méthode `deleteAny()` de la policy), et chaque ligne choisie passe encore
 * la méthode `delete()` (`authorizeIndividualRecords()`), si bien qu'une
 * ligne que la policy refuse reste en place. Un appel forgé, qui monte
 * l'action sans le bouton comme le ferait une requête Livewire écrite à la
 * main, ne supprime rien non plus. Le super administrateur garde la
 * suppression groupée par la liste des capacités
 * (`AppServiceProvider::ouvrirLesOutilsAuSuperAdministrateur()`), sans
 * permission `DeleteAny:X` en base.
 */

dataset('tables du panneau à suppression groupée', [
    'comptes' => [ListUsers::class, 'User', static fn (): Collection => User::factory()->count(2)->create()],
    'séances' => [ListWorkouts::class, 'Workout', static fn (): Collection => Workout::factory()->count(2)->create()],
    'objectifs' => [ListGoals::class, 'Goal', static fn (): Collection => Goal::factory()->count(2)->create()],
    'exercices' => [ListExercises::class, 'Exercise', static fn (): Collection => Exercise::factory()->count(2)->create()],
    'compléments' => [ListSupplements::class, 'Supplement', static fn (): Collection => Supplement::factory()->count(2)->create()],
    'badges' => [ListAchievements::class, 'Achievement', static fn (): Collection => Achievement::factory()->count(2)->create()],
    'rôles' => [ListRoles::class, 'Role', static fn (): Collection => new Collection([suppressionGroupeeRole(), suppressionGroupeeRole()])],
    'exceptions enregistrées' => [ListExceptions::class, 'ExceptionEnregistree', static fn (): Collection => new Collection([suppressionGroupeeException(), suppressionGroupeeException()])],
]);

/**
 * Un rôle du panneau, autre que celui du super administrateur.
 */
function suppressionGroupeeRole(): Role
{
    return Role::query()->create(['name' => 'role_'.Str::lower(Str::random(8)), 'guard_name' => 'admin']);
}

/**
 * Une exception enregistrée, comme le paquet en écrit une.
 */
function suppressionGroupeeException(): ExceptionEnregistree
{
    return ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'message' => 'boum '.Str::random(6),
        'file' => 'app/Http/Controllers/Controller.php',
        'line' => 12,
        'trace' => [],
        'method' => 'GET',
        'path' => 'seances',
    ]);
}

/**
 * Le nombre de lignes encore en base parmi celles données.
 *
 * @param  Collection<int, covariant Model>  $lignes
 */
function suppressionGroupeeRestantes(Collection $lignes): int
{
    $premiere = $lignes->first();

    return $premiere instanceof Model ? $premiere->newQuery()->whereKey($lignes->modelKeys())->count() : 0;
}

/**
 * La suppression groupée appelée comme le ferait une requête Livewire écrite
 * à la main : l'action montée par son nom, sans le bouton, puis exécutée.
 *
 * @param  Collection<int, covariant Model>  $lignes
 */
function suppressionGroupeeForcee(string $page, Collection $lignes): void
{
    Livewire::test($page)
        ->selectTableRecords($lignes)
        ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])
        ->call('callMountedAction');
}

it('cache la suppression groupée à qui ne peut que lire, et un appel forgé ne supprime rien', function (string $page, string $modele, Closure $creer): void {
    $this->actingAs(FilamentAdminPanel::admin(["ViewAny:{$modele}", "View:{$modele}"]), 'admin');
    /** @var Collection<int, Model> $lignes */
    $lignes = $creer();

    Livewire::test($page)->assertTableBulkActionHidden('delete');
    suppressionGroupeeForcee($page, $lignes);

    expect(suppressionGroupeeRestantes($lignes))->toBe(2);
})->with('tables du panneau à suppression groupée');

it('cache aussi la suppression groupée à qui peut supprimer une ligne, sans DeleteAny', function (string $page, string $modele, Closure $creer): void {
    $this->actingAs(FilamentAdminPanel::admin(["ViewAny:{$modele}", "View:{$modele}", "Delete:{$modele}"]), 'admin');
    /** @var Collection<int, Model> $lignes */
    $lignes = $creer();

    Livewire::test($page)->assertTableBulkActionHidden('delete');
    suppressionGroupeeForcee($page, $lignes);

    expect(suppressionGroupeeRestantes($lignes))->toBe(2);
})->with('tables du panneau à suppression groupée');

it('ne supprime, avec DeleteAny sans Delete, aucune des lignes choisies', function (string $page, string $modele, Closure $creer): void {
    $this->actingAs(FilamentAdminPanel::admin(["ViewAny:{$modele}", "View:{$modele}", "DeleteAny:{$modele}"]), 'admin');
    /** @var Collection<int, Model> $lignes */
    $lignes = $creer();

    Livewire::test($page)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', $lignes);

    expect(suppressionGroupeeRestantes($lignes))->toBe(2);
})->with('tables du panneau à suppression groupée');

it('laisse en place, dans une suppression groupée, la ligne que la policy refuse', function (string $page, string $modele, Closure $creer): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions($modele)), 'admin');
    /** @var Collection<int, Model> $lignes */
    $lignes = $creer();
    [$epargnee, $supprimee] = $lignes->all();
    Gate::before(static fn (mixed $administrateur, string $capacite, array $arguments): ?bool => $capacite === 'delete'
        && ($arguments[0] ?? null) instanceof Model
        && $arguments[0]->is($epargnee) ? false : null);

    Livewire::test($page)->callTableBulkAction('delete', $lignes);

    expect($epargnee->newQuery()->whereKey($epargnee->getKey())->exists())->toBeTrue()
        ->and($supprimee->newQuery()->whereKey($supprimee->getKey())->exists())->toBeFalse();
})->with('tables du panneau à suppression groupée');

it('supprime en groupe pour qui porte DeleteAny et Delete', function (string $page, string $modele, Closure $creer): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions($modele)), 'admin');
    /** @var Collection<int, Model> $lignes */
    $lignes = $creer();

    Livewire::test($page)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', $lignes);

    expect(suppressionGroupeeRestantes($lignes))->toBe(0);
})->with('tables du panneau à suppression groupée');

it('laisse le super administrateur supprimer en groupe, sans permission DeleteAny en base', function (string $page, string $modele, Closure $creer): void {
    $superAdministrateur = FilamentAdminPanel::admin(["ViewAny:{$modele}", "View:{$modele}", "Delete:{$modele}"]);
    $superAdministrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $this->actingAs($superAdministrateur, 'admin');
    /** @var Collection<int, Model> $lignes */
    $lignes = $creer();

    Livewire::test($page)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', $lignes);

    expect(suppressionGroupeeRestantes($lignes))->toBe(0);
})->with('tables du panneau à suppression groupée');

it('garde le rôle du super administrateur, et son titulaire le panneau, face à qui n’a pas DeleteAny:Role', function (): void {
    $titulaire = Admin::factory()->create();
    $role = Role::findOrCreate('super_admin', 'admin');
    assert($role instanceof Role);
    $titulaire->assignRole($role);
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Role', 'View:Role', 'Delete:Role']), 'admin');

    Livewire::test(ListRoles::class)->assertTableBulkActionHidden('delete');
    suppressionGroupeeForcee(ListRoles::class, new Collection([$role]));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue()
        ->and($titulaire->fresh()?->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});
