<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
 * Une sauvegarde ne vaut que si l'on peut la rouvrir : l'archive doit être
 * chiffrée avec le mot de passe configuré, et le dump qu'elle contient doit
 * être complet. Le test refait tout le
 * chemin par la commande planifiée, mysqldump compris, sur le disque
 * `sauvegardes` détourné vers un dossier jetable.
 */
it('produit une archive chiffrée dont le dump est complet et relisible', function (): void {
    $dossier = storage_path('framework/testing/sauvegardes-'.uniqid());
    File::ensureDirectoryExists($dossier);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);
    Config::set('backup.backup.password', 'mot-de-passe-de-test');
    Config::set('backup.notifications.notifications', []);
    // Le paquet fige sa configuration dans un singleton au démarrage : on la refait lire.
    app()->forgetInstance(\Spatie\Backup\Config\Config::class);

    try {
        expect(Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]))->toBe(0, Artisan::output());

        $nom = config('backup.backup.name');
        assert(is_string($nom));
        $archives = glob($dossier.'/'.$nom.'/*.zip');
        assert(is_array($archives));
        expect($archives)->toHaveCount(1);

        $zip = new ZipArchive();
        expect($zip->open($archives[0]))->toBeTrue();

        $entree = collect(range(0, $zip->numFiles - 1))
            ->map(fn (int $i): string => (string) $zip->getNameIndex($i))
            ->first(fn (string $nom): bool => str_ends_with($nom, '.sql'));
        expect($entree)->not->toBeNull('aucun dump SQL dans l’archive');
        assert(is_string($entree));

        // Sans le mot de passe, l'entrée chiffrée reste illisible.
        expect($zip->getFromName($entree))->toBeFalse('le dump se lit sans mot de passe : l’archive n’est pas chiffrée');

        $zip->setPassword('mot-de-passe-de-test');
        $dump = $zip->getFromName($entree);
        $zip->close();

        // Le dump est produit sans commentaires : sa complétude se lit à ses tables.
        expect($dump)->toBeString()
            ->and($dump)->toContain('CREATE TABLE `users`')
            ->and($dump)->toContain('CREATE TABLE `sets`')
            ->and($dump)->toContain('CREATE TABLE `notification_preferences`');
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('refuse d’écrire une archive sans mot de passe, d’où que vienne la demande', function (): void {
    $dossier = storage_path('framework/testing/sauvegardes-'.uniqid());
    File::ensureDirectoryExists($dossier);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);
    Config::set('backup.backup.password');
    Config::set('backup.notifications.notifications', []);
    // Le paquet fige sa configuration dans un singleton au démarrage : on la refait lire.
    app()->forgetInstance(\Spatie\Backup\Config\Config::class);

    try {
        expect(Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]))->toBe(1);
        expect(Artisan::output())->toContain('BACKUP_ARCHIVE_PASSWORD');
        $nom = config('backup.backup.name');
        assert(is_string($nom));
        expect(glob($dossier.'/'.$nom.'/*.zip'))->toBe([]);
    } finally {
        File::deleteDirectory($dossier);
    }
});

/*
 * Le client `mysqldump` de l'image est celui de MariaDB : il vérifie le
 * certificat du serveur, que MySQL signe lui-même, et la sauvegarde de
 * production tombait sur « self-signed certificate in certificate chain »
 * (#1740). Le client MySQL du poste de travail ne connaît pas `--skip-ssl`,
 * d'où le préfixe `loose-`, qu'il ignore avec un avertissement.
 */
it('dumpe sans vérifier le certificat du serveur ni lire les tablespaces', function (): void {
    $dumper = \Spatie\Backup\Tasks\Backup\DbDumperFactory::createFromConnection('mysql');
    expect($dumper)->toBeInstanceOf(\Spatie\DbDumper\Databases\MySql::class);
    assert($dumper instanceof \Spatie\DbDumper\Databases\MySql);

    $commande = $dumper->getDumpCommand('/tmp/dump.sql', '/tmp/identifiants.cnf');

    expect($commande)->toContain('--loose-skip-ssl')
        ->and($commande)->toContain('--no-tablespaces')
        ->and($commande)->toContain('--single-transaction');
});
