<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $racine = config('filesystems.disks.sauvegardes.root');
    assert(is_string($racine));
    File::ensureDirectoryExists($racine);
});

/**
 * Le greffon n'affiche « Créer une sauvegarde » qu'à qui passe `create-backup`.
 * Shield ne connaît pas cette capacité et ne pose aucune porte pour le super
 * administrateur : sans les portes de l'application, personne ne voyait le bouton.
 */
it('propose la sauvegarde manuelle au super administrateur', function (): void {
    $admin = Admin::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($admin, 'admin')
        ->get('/backoffice/backups')
        ->assertOk()
        ->assertSee('Créer une sauvegarde');
});

it('refuse la page à un administrateur ordinaire', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/backoffice/backups')
        ->assertForbidden();
});
