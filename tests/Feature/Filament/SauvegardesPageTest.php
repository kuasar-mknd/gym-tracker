<?php

declare(strict_types=1);

use App\Filament\Sauvegardes\EtatDesSauvegardes;
use App\Filament\Sauvegardes\ListeDesSauvegardes;
use App\Filament\Sauvegardes\PageDesSauvegardes;
use App\Models\Admin;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process as Processus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Finder\Finder;
use Livewire\Livewire;
use ShuvroRoy\FilamentSpatieLaravelBackup\Enums\BackupType;
use ShuvroRoy\FilamentSpatieLaravelBackup\Jobs\CreateBackupJob;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Exception\ProcessTimedOutException as DelaiDepasse;
use Symfony\Component\Process\Process;
use Tests\Support\FilamentAdminPanel;
use Tests\Support\PartageEndormi;

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

/*
 * Le greffon ne garde que son bouton : `create()` est une méthode Livewire
 * publique, qu'une requête forgée appelle sans lui. Un administrateur qui ne
 * pouvait que télécharger mettait ainsi une sauvegarde en file.
 */
it('refuse une sauvegarde forgée à qui ne peut pas en créer', function (): void {
    Queue::fake();
    $this->actingAs(FilamentAdminPanel::admin(['download-backup']), 'admin');

    Livewire::test(PageDesSauvegardes::class)
        ->call('create', BackupType::ONLY_DATABASE->value)
        ->assertForbidden();

    Queue::assertNothingPushed();
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

/**
 * Un dossier des sauvegardes jetable, qui porte une archive de la nuit au nom
 * que la sauvegarde lui donnerait ; rend le nom de l'archive.
 */
function sauvegardesPageDossierAvecUneArchive(string $racine): string
{
    $archive = '2026-10-04-02-30-00.zip';
    File::ensureDirectoryExists($racine.'/'.config()->string('backup.backup.name'));
    File::put($racine.'/'.config()->string('backup.backup.name').'/'.$archive, 'archive');

    config()->set('filesystems.disks.sauvegardes.root', $racine);
    Storage::forgetDisk('sauvegardes');

    return $archive;
}

/**
 * Un super administrateur, qui voit tout de la page.
 */
function sauvegardesPageSuperAdministrateur(): Admin
{
    $admin = Admin::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $admin;
}

it('liste les archives quand le dossier des sauvegardes répond', function (): void {
    $racine = storage_path('framework/testing/sauvegardes-'.uniqid());
    $archive = sauvegardesPageDossierAvecUneArchive($racine);

    try {
        $this->actingAs(sauvegardesPageSuperAdministrateur(), 'admin')
            ->get('/backoffice/backups')
            ->assertOk()
            ->assertSee($archive)
            ->assertSee('Créer une sauvegarde')
            ->assertDontSee('Le dossier des sauvegardes ne répond pas');
    } finally {
        File::deleteDirectory($racine);
    }
});

/*
 * Le greffon liste le disque sans délai : sur un partage qui ne répond plus,
 * la page attendait sans fin, et le travailleur d'Octane qui la servait
 * restait pris (#1929). Elle sonde d'abord le dossier, dans un délai borné, et
 * dit ce qu'il en est plutôt que de lister.
 */
it('dit vite que le dossier des sauvegardes ne répond pas, sans le lister ni proposer d’y écrire', function (): void {
    $racine = storage_path('framework/testing/sauvegardes-'.uniqid());
    $archive = sauvegardesPageDossierAvecUneArchive($racine);
    $endormies = PartageEndormi::commandes(['ls']);
    $this->actingAs(sauvegardesPageSuperAdministrateur(), 'admin');

    try {
        $debut = microtime(true);
        $reponse = PartageEndormi::enTeteDuPath($endormies, fn (): mixed => $this->get('/backoffice/backups'));
        assert($reponse instanceof TestResponse);

        expect(microtime(true) - $debut)->toBeLessThan(PageDesSauvegardes::DELAI_DE_LA_SONDE_EN_SECONDES + 5);

        $reponse->assertOk()
            ->assertSee('Le dossier des sauvegardes ne répond pas')
            ->assertSee(sprintf("Il n'a pas répondu en %d s", PageDesSauvegardes::DELAI_DE_LA_SONDE_EN_SECONDES))
            ->assertSee('BACKUP_HOST_PATH')
            ->assertDontSee($archive)
            ->assertDontSee('Créer une sauvegarde');
    } finally {
        File::deleteDirectory($racine);
        File::deleteDirectory($endormies);
    }
});

/*
 * La page ne propose plus de sauvegarde quand le dossier se tait, mais celle
 * ouverte avant qu'il s'endorme, ou une requête forgée, appelle encore
 * `create()` : la sauvegarde serait allée attendre le partage dans un
 * travailleur de la file.
 */
it('ne met plus de sauvegarde en file quand le dossier cesse de répondre', function (): void {
    Queue::fake();
    $this->actingAs(sauvegardesPageSuperAdministrateur(), 'admin');

    $composant = Livewire::test(PageDesSauvegardes::class)
        ->assertSee('Créer une sauvegarde')
        ->call('create', BackupType::ONLY_DATABASE->value)
        ->assertOk();

    Queue::assertPushed(CreateBackupJob::class, 1);

    Processus::fake(fn () => throw new ProcessTimedOutException(
        new DelaiDepasse(new Process(['ls', '-A', 'sauvegardes']), DelaiDepasse::TYPE_GENERAL),
        new FakeProcessResult(),
    ));

    $composant->call('create', BackupType::ONLY_DATABASE->value)
        ->assertOk()
        ->assertNotified('Le dossier des sauvegardes ne répond pas');

    Queue::assertPushed(CreateBackupJob::class, 1);
});

/*
 * La page ne rend ses tableaux que si le dossier répondait à son ouverture,
 * mais chacun se rafraîchit seul toutes les minutes : un partage qui s'endort
 * page ouverte aurait pris un travailleur à chaque rafraîchissement.
 */
it('ne liste plus rien au rafraîchissement d’un tableau quand le dossier cesse de répondre', function (string $tableau, Closure $temoin): void {
    $racine = storage_path('framework/testing/sauvegardes-'.uniqid());
    $archive = sauvegardesPageDossierAvecUneArchive($racine);
    $this->actingAs(FilamentAdminPanel::admin(['download-backup']), 'admin');

    try {
        $composant = Livewire::test($tableau)
            ->assertSee($temoin($archive))
            ->assertDontSee('Le dossier des sauvegardes ne répond pas');

        Processus::fake(fn () => throw new ProcessTimedOutException(
            new DelaiDepasse(new Process(['ls', '-A', $racine]), DelaiDepasse::TYPE_GENERAL),
            new FakeProcessResult(),
        ));

        $composant->call('$refresh')
            ->assertSee('Le dossier des sauvegardes ne répond pas')
            ->assertDontSee($temoin($archive));
    } finally {
        File::deleteDirectory($racine);
    }
})->with([
    'la liste des archives' => [ListeDesSauvegardes::class, fn (string $archive): string => $archive],
    'l’état des sauvegardes' => [EtatDesSauvegardes::class, fn (string $archive): string => config()->string('backup.monitor_backups.0.name')],
]);

/*
 * Un rafraîchissement ne rapporte du tableau que son nom, tiré de sa classe, et
 * Livewire doit en retrouver la classe dans une requête où rien ne l'a encore
 * résolue : il la reconstruit segment par segment. Une classe dont le nom ne
 * fait pas l'aller-retour (un sigle, un chiffre) devrait s'enregistrer auprès
 * du panneau, ou chaque rafraîchissement tomberait en erreur ; `Livewire::test()`,
 * qui garde la classe sous la main, ne le verrait pas.
 */
it('retrouve les tableaux de la page par leur seul nom, comme à chaque rafraîchissement', function (string $tableau): void {
    $repertoire = app('livewire.finder');
    assert($repertoire instanceof Finder);

    expect($repertoire->resolveClassComponentClassName((string) $repertoire->normalizeName($tableau)))->toBe($tableau);
})->with([ListeDesSauvegardes::class, EtatDesSauvegardes::class]);
