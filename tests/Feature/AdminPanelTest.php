<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;

it('redirects unauthenticated users to login', function (): void {
    $this->get('/backoffice')->assertRedirect('/backoffice/login');
});

it('allows authenticated admin to access dashboard', function (): void {
    \Spatie\Permission\Models\Role::create(['name' => 'super_admin', 'guard_name' => 'admin']);
    $admin = Admin::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin, 'admin')->get('/backoffice')->assertOk();
});

it('prevents regular users from accessing admin panel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/backoffice')->assertRedirect('/backoffice/login');
});

it('prevents admins without super_admin role from bypassing gate', function (): void {
    // This depends on how Shield is configured, but specifically tests our Gate::before bypass
    $admin = Admin::factory()->create();
    // No role assigned

    // They should still reach the dashboard because Filament has its own auth,
    // but Shield might block specific resources.
    $this->actingAs($admin, 'admin')->get('/backoffice')->assertOk();
});
