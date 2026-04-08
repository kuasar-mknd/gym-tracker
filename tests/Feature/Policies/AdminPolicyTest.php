<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Policies\AdminPolicy;

describe('AdminPolicy', function () {
    it('allows viewAny when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('view_any_admin')->andReturn(true);

        $policy = new AdminPolicy();

        expect($policy->viewAny($user))->toBeTrue();
    });

    it('denies viewAny when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('view_any_admin')->andReturn(false);

        $policy = new AdminPolicy();

        expect($policy->viewAny($user))->toBeFalse();
    });

    it('allows view when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('view_admin')->andReturn(true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->view($user, $admin))->toBeTrue();
    });

    it('denies view when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('view_admin')->andReturn(false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->view($user, $admin))->toBeFalse();
    });

    it('allows create when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('create_admin')->andReturn(true);

        $policy = new AdminPolicy();

        expect($policy->create($user))->toBeTrue();
    });

    it('denies create when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('create_admin')->andReturn(false);

        $policy = new AdminPolicy();

        expect($policy->create($user))->toBeFalse();
    });

    it('allows update when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('update_admin')->andReturn(true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->update($user, $admin))->toBeTrue();
    });

    it('denies update when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('update_admin')->andReturn(false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->update($user, $admin))->toBeFalse();
    });

    it('allows delete when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('delete_admin')->andReturn(true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->delete($user, $admin))->toBeTrue();
    });

    it('denies delete when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('delete_admin')->andReturn(false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->delete($user, $admin))->toBeFalse();
    });

    it('allows restore when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('restore_admin')->andReturn(true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->restore($user, $admin))->toBeTrue();
    });

    it('denies restore when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('restore_admin')->andReturn(false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->restore($user, $admin))->toBeFalse();
    });

    it('allows forceDelete when user has permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('force_delete_admin')->andReturn(true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->forceDelete($user, $admin))->toBeTrue();
    });

    it('denies forceDelete when user lacks permission', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->with('force_delete_admin')->andReturn(false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->forceDelete($user, $admin))->toBeFalse();
    });
});
