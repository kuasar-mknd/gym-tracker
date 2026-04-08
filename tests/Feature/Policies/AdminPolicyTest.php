<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Policies\AdminPolicy;
use Illuminate\Support\Facades\Gate;

describe('AdminPolicy', function () {
    it('allows viewAny when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('view_any_admin', fn () => true);

        $policy = new AdminPolicy();

        expect($policy->viewAny($user))->toBeTrue();
    });

    it('denies viewAny when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('view_any_admin', fn () => false);

        $policy = new AdminPolicy();

        expect($policy->viewAny($user))->toBeFalse();
    });

    it('allows view when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('view_admin', fn () => true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->view($user, $admin))->toBeTrue();
    });

    it('denies view when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('view_admin', fn () => false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->view($user, $admin))->toBeFalse();
    });

    it('allows create when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('create_admin', fn () => true);

        $policy = new AdminPolicy();

        expect($policy->create($user))->toBeTrue();
    });

    it('denies create when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('create_admin', fn () => false);

        $policy = new AdminPolicy();

        expect($policy->create($user))->toBeFalse();
    });

    it('allows update when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('update_admin', fn () => true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->update($user, $admin))->toBeTrue();
    });

    it('denies update when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('update_admin', fn () => false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->update($user, $admin))->toBeFalse();
    });

    it('allows delete when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('delete_admin', fn () => true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->delete($user, $admin))->toBeTrue();
    });

    it('denies delete when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('delete_admin', fn () => false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->delete($user, $admin))->toBeFalse();
    });

    it('allows restore when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('restore_admin', fn () => true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->restore($user, $admin))->toBeTrue();
    });

    it('denies restore when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('restore_admin', fn () => false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->restore($user, $admin))->toBeFalse();
    });

    it('allows forceDelete when user has permission', function () {
        $user = User::factory()->make();
        Gate::define('force_delete_admin', fn () => true);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->forceDelete($user, $admin))->toBeTrue();
    });

    it('denies forceDelete when user lacks permission', function () {
        $user = User::factory()->make();
        Gate::define('force_delete_admin', fn () => false);
        $admin = Admin::factory()->make();

        $policy = new AdminPolicy();

        expect($policy->forceDelete($user, $admin))->toBeFalse();
    });
});
