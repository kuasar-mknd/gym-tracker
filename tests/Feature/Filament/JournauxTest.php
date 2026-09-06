<?php

declare(strict_types=1);

use App\Models\Admin;
use Spatie\Permission\Models\Role;

/**
 * Le lecteur de journaux vit sous /backoffice mais hors du panneau : il
 * doit tenir la même porte, session du panneau comprise.
 */
it('ouvre les journaux au super administrateur', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get('/backoffice/journaux')
        ->assertOk()
        ->assertSee('Log Viewer');
});

it('se refuse à un administrateur ordinaire', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/backoffice/journaux')
        ->assertForbidden();
});

it('renvoie un invité vers la connexion du panneau', function (): void {
    $this->get('/backoffice/journaux')->assertRedirect();
});

it('donne au super administrateur les portes des outils dans le menu', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get('/backoffice')
        ->assertOk()
        ->assertSee('Journaux')
        ->assertSee('Horizon')
        ->assertSee('Tâches planifiées')
        ->assertSee('Exceptions')
        ->assertSee('Santé');
});
