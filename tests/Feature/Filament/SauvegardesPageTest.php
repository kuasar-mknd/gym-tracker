<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

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

/**
 * L'administrateur ci-dessus n'entre même pas au panneau, faute de rôle : son
 * 403 ne dit rien de la page. Celui-ci y entre, et la page s'ouvrait à lui en
 * lecture : la liste des archives, leurs dates et leurs tailles.
 */
it('cache la page et son lien à un administrateur du panneau sans capacité de sauvegarde', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:Exercise']), 'admin');

    $this->get('/backoffice')->assertOk()->assertDontSee('/backoffice/backups', escape: false);
    $this->get('/backoffice/backups')->assertForbidden();
});

it('ouvre la page à qui peut télécharger une sauvegarde, sans lui proposer d’en créer', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['download-backup']), 'admin')
        ->get('/backoffice/backups')
        ->assertOk()
        ->assertDontSee('Créer une sauvegarde');
});

/**
 * Le greffon sondait toutes les quatre secondes, et chaque sondage relit le
 * partage monté depuis l'hôte : c'était la transaction la plus fréquente de
 * la semaine tant que la page restait ouverte.
 */
it('ne sonde le partage des sauvegardes qu’une fois par minute', function (): void {
    /** @var \ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin $greffon */
    $greffon = filament()->getPlugin('filament-spatie-backup');

    expect($greffon->getPollingInterval())->toBe('60s');
});
