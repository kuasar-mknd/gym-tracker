<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Policies\UserPolicy;

/**
 * The back-office authenticates App\Models\Admin on the "admin" guard, a table
 * with its own id sequence. UserPolicy used to compare getAuthIdentifier()
 * without checking the type, so an Admin holding no permission whatsoever was
 * granted view/update/delete/restore/forceDelete/replicate over the User that
 * happened to share their id — and admin ids being small integers, that meant
 * the earliest registered users.
 *
 * The five sibling policies reachable from the panel (Workout, Goal, Exercise,
 * Achievement, Supplement) all guard with `instanceof User`; UserPolicy was the
 * only one that did not.
 */
it('denies an admin every self-granted right over the user sharing its id', function (string $ability): void {
    $admin = Admin::factory()->create();
    $user = User::factory()->create(['id' => $admin->getKey()]);

    $policy = new UserPolicy();

    expect($user->getKey())->toBe($admin->getKey())
        ->and($policy->{$ability}($admin, $user))->toBeFalse();
})->with(['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate']);

it('still lets a user act on their own record', function (string $ability): void {
    $user = User::factory()->create();

    $policy = new UserPolicy();

    expect($policy->{$ability}($user, $user))->toBeTrue();
})->with(['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate']);

it('still denies a user acting on somebody else', function (string $ability): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $policy = new UserPolicy();

    expect($policy->{$ability}($user, $other))->toBeFalse();
})->with(['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate']);
