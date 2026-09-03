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

it('refuse le panneau a un admin sans role ni permission Shield', function (): void {
    // Une ligne dans la table admins ne suffit pas : canAccessPanel exige
    // qu'un role ou une permission lui ait ete accorde.
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')->get('/backoffice')->assertForbidden();
});
