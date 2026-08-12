<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The abilities Shield actually generates for the Role resource, and that the
 * admin panel actually reaches. Keys are the policy method, values the exact
 * ability string the method must consult.
 */
const REACHABLE_ROLE_ABILITIES = [
    'viewAny' => 'ViewAny:Role',
    'view' => 'View:Role',
    'create' => 'Create:Role',
    'update' => 'Update:Role',
    'delete' => 'Delete:Role',
];

function adminWithRoleAbilities(array $abilities): Admin
{
    $admin = Admin::factory()->create();

    foreach ($abilities as $ability) {
        Permission::findOrCreate($ability, 'admin');
    }

    $admin->givePermissionTo($abilities);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin->fresh();
}

it('is the policy Laravel resolves for the configured role model', function (): void {
    $roleModel = config('permission.models.role');

    expect(Gate::getPolicyFor($roleModel))->toBeInstanceOf(RolePolicy::class);
});

it('grants an admin holding exactly the matching ability', function (string $method, string $ability): void {
    $admin = adminWithRoleAbilities([$ability]);
    $policy = new RolePolicy();

    expect($policy->{$method}($admin))->toBeTrue();
})->with(
    collect(REACHABLE_ROLE_ABILITIES)
        ->map(fn (string $ability, string $method): array => [$method, $ability])
        ->all()
);

/**
 * The mutation killer: the admin holds every other Role ability. A method that
 * consults the wrong ability string (a copy/paste between methods) would still
 * return true here, so this is what tells a correct policy from a cross-wired one.
 */
it('denies an admin holding every other role ability but not the matching one', function (string $method, string $ability): void {
    $others = array_values(array_diff(array_values(REACHABLE_ROLE_ABILITIES), [$ability]));
    $admin = adminWithRoleAbilities($others);
    $policy = new RolePolicy();

    expect($policy->{$method}($admin))->toBeFalse();
})->with(
    collect(REACHABLE_ROLE_ABILITIES)
        ->map(fn (string $ability, string $method): array => [$method, $ability])
        ->all()
);

/**
 * Role management lives behind the admin guard. An application user has no
 * roles table entry at all, so every method must fall through to a denied gate.
 */
it('denies an application user every reachable role ability', function (string $method): void {
    $user = User::factory()->create();
    $policy = new RolePolicy();

    expect($policy->{$method}($user))->toBeFalse();
})->with(array_keys(REACHABLE_ROLE_ABILITIES));

/**
 * Deny-by-default invariant over the whole class, scaffolding included: nothing
 * a RolePolicy method can be asked returns true for a principal with no
 * permission at all. Catches a `return true` slipped into any method.
 */
it('grants nothing at all to a principal with no permissions', function (string $principal): void {
    $actor = $principal === 'admin' ? Admin::factory()->create() : User::factory()->create();
    $policy = new RolePolicy();

    $abilityMethods = collect((new ReflectionClass(RolePolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isStatic() || $method->getNumberOfParameters() !== 1)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values();

    $granted = $abilityMethods
        ->filter(fn (string $method): bool => $policy->{$method}($actor) === true)
        ->values()
        ->all();

    expect($granted)->toBe([])
        ->and($abilityMethods->all())->toBe([
            'viewAny', 'view', 'create', 'update', 'delete', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ]);
})->with(['admin', 'user']);
