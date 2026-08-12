<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The abilities the admin panel's UserResource actually reaches. Keys are the
 * policy method, values the exact ability string it must consult.
 */
const REACHABLE_USER_ABILITIES = [
    'viewAny' => 'ViewAny:User',
    'view' => 'View:User',
    'create' => 'Create:User',
    'update' => 'Update:User',
    'delete' => 'Delete:User',
];

/** Methods that take the acted-upon user as a second argument. */
const USER_ABILITIES_ON_A_RECORD = ['view', 'update', 'delete'];

function adminWithUserAbilities(array $abilities): Admin
{
    $admin = Admin::factory()->create();

    foreach ($abilities as $ability) {
        Permission::findOrCreate($ability, 'admin');
    }

    if ($abilities !== []) {
        $admin->givePermissionTo($abilities);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin->fresh();
}

/**
 * UserPolicy compares raw auth identifiers, so an admin and a user that happen
 * to share an id are treated as the same principal. Tests about *permissions*
 * must not accidentally land on that collision, hence this helper.
 */
function userWithIdOtherThan(Admin $admin): User
{
    do {
        $user = User::factory()->create();
    } while ($user->getAuthIdentifier() === $admin->getAuthIdentifier());

    return $user;
}

it('is the policy Laravel resolves for the user model', function (): void {
    expect(Gate::getPolicyFor(User::class))->toBeInstanceOf(UserPolicy::class);
});

describe('self service', function (): void {
    it('lets a user act on their own account', function (string $method): void {
        $user = User::factory()->create();
        $policy = new UserPolicy();

        expect($policy->{$method}($user, $user))->toBeTrue();
    })->with(USER_ABILITIES_ON_A_RECORD);

    it('stops a user acting on somebody else\'s account', function (string $method): void {
        $actor = User::factory()->create();
        $victim = User::factory()->create();
        $policy = new UserPolicy();

        expect($actor->getAuthIdentifier())->not->toBe($victim->getAuthIdentifier())
            ->and($policy->{$method}($actor, $victim))->toBeFalse();
    })->with(USER_ABILITIES_ON_A_RECORD);

    it('stops a user listing or creating accounts, which is an admin panel affair', function (): void {
        $user = User::factory()->create();
        $policy = new UserPolicy();

        expect($policy->viewAny($user))->toBeFalse()
            ->and($policy->create($user))->toBeFalse();
    });
});

describe('admin permissions', function (): void {
    it('lets an admin holding the matching ability act on any account', function (string $method): void {
        $admin = adminWithUserAbilities([REACHABLE_USER_ABILITIES[$method]]);
        $victim = userWithIdOtherThan($admin);
        $policy = new UserPolicy();

        expect($policy->{$method}($admin, $victim))->toBeTrue();
    })->with(USER_ABILITIES_ON_A_RECORD);

    /**
     * The admin holds every other User ability. A method wired to the wrong
     * ability string would still pass a plain happy-path test; it cannot pass this.
     */
    it('denies an admin holding every other user ability but not the matching one', function (string $method): void {
        $ability = REACHABLE_USER_ABILITIES[$method];
        $others = array_values(array_diff(array_values(REACHABLE_USER_ABILITIES), [$ability]));
        $admin = adminWithUserAbilities($others);
        $victim = userWithIdOtherThan($admin);
        $policy = new UserPolicy();

        expect($policy->{$method}($admin, $victim))->toBeFalse();
    })->with(USER_ABILITIES_ON_A_RECORD);

    it('gates the collection level abilities on their own permission', function (string $method): void {
        $ability = REACHABLE_USER_ABILITIES[$method];
        $granted = adminWithUserAbilities([$ability]);
        $others = array_values(array_diff(array_values(REACHABLE_USER_ABILITIES), [$ability]));
        $denied = adminWithUserAbilities($others);
        $policy = new UserPolicy();

        expect($policy->{$method}($granted))->toBeTrue()
            ->and($policy->{$method}($denied))->toBeFalse();
    })->with(['viewAny', 'create']);
});

/**
 * Deny-by-default invariant across the whole class, Shield scaffolding
 * included: an admin with no permissions must be granted nothing on a user
 * that is not itself. Catches a `return true` slipped into any method.
 */
it('grants a permission-less admin nothing over another account', function (): void {
    $admin = Admin::factory()->create();
    $victim = userWithIdOtherThan($admin);
    $policy = new UserPolicy();

    $methods = collect(new ReflectionClass(UserPolicy::class)->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isStatic())
        ->filter(fn (ReflectionMethod $method): bool => $method->getReturnType() instanceof ReflectionNamedType
            && $method->getReturnType()->getName() === 'bool')
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values();

    $granted = $methods
        ->filter(function (string $method) use ($policy, $admin, $victim): bool {
            $arity = new ReflectionMethod(UserPolicy::class, $method)->getNumberOfParameters();

            return ($arity === 2 ? $policy->{$method}($admin, $victim) : $policy->{$method}($admin)) === true;
        })
        ->values()
        ->all();

    expect($granted)->toBe([])
        ->and($methods->all())->toBe([
            'viewAny', 'view', 'create', 'update', 'delete', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ]);
});
