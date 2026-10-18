<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\Events\DumpingDatabase;
use Spatie\DbDumper\Databases\MySql;

/*
 * Une sauvegarde ne vaut que si l'on peut la rouvrir : l'archive doit être
 * chiffrée avec le mot de passe configuré, et le dump qu'elle contient doit
 * être complet. Le test appelle la commande comme le panneau, avec un
 * tableau d'options, mysqldump compris, sur le disque `sauvegardes` détourné
 * vers un dossier jetable ; la ligne que lance le planificateur passe par
 * `SauvegardesPlanifieesTest`.
 *
 * Le dossier de travail du paquet y passe aussi : `backup:run` vide le sien
 * en commençant et l'efface en finissant, et celui de la configuration est le
 * même pour tous les processus d'une suite parallèle, où
 * `SauvegardesPlanifieesTest` effaçait le dump de celui-ci (et réciproquement).
 */
it('produit une archive chiffrée dont le dump est complet et relisible', function (): void {
    $dossier = storage_path('framework/testing/sauvegardes-'.uniqid());
    File::ensureDirectoryExists($dossier);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);
    Config::set('backup.backup.temporary_directory', $dossier.'/.temp');
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
        // Il ne touche ni aux GTID ni au journal binaire du serveur, que le
        // compte de l'application ne pourrait pas rejouer en restaurant.
        expect($dump)->toBeString()
            ->and($dump)->toContain('CREATE TABLE `users`')
            ->and($dump)->toContain('CREATE TABLE `sets`')
            ->and($dump)->toContain('CREATE TABLE `notification_preferences`')
            ->and($dump)->not->toContain('GTID_PURGED')
            ->and($dump)->not->toContain('SQL_LOG_BIN');
    } finally {
        File::deleteDirectory($dossier);
    }
});

/*
 * Une archive ne vaut que si son dump se restaure : le test le rejoue, par le
 * client `mysql` qui accompagne le `mysqldump` de la sauvegarde et avec le
 * compte de l'application, dans une base neuve qu'il peut créer (le suffixe
 * `_test_` des éclats de Paratest, que Sail et la CI lui ouvrent), après avoir
 * validé une ligne dans chacune des trois tables de Pulse : la transaction du
 * test, que le dump ne voit pas, n'y suffirait pas.
 *
 * Le client de l'image, celui de MariaDB, ne reconnaît pas les colonnes
 * générées d'un serveur MySQL : il recopiait l'empreinte `key_hash` de Pulse
 * dans ses INSERT, que MySQL refuse, en 8.4 comme en 9.7 (« The value
 * specified for generated column 'key_hash' in table 'pulse_aggregates' is not
 * allowed ») ; la restauration s'arrêtait là, sans les tables qui suivent,
 * `sets` et `users` comprises. Le dump laisse donc de côté les lignes de
 * Pulse, des métriques, et garde ses tables (AppServiceProvider). Le client
 * MySQL des runners de la CI nomme les colonnes de ses INSERT, sans
 * l'empreinte, et ignore l'option grâce à `loose-` : il ne voit pas le défaut.
 * La commande que le paquet a lancée doit donc porter l'option pour la base
 * dumpée, et aucune autre table n'a de colonne générée.
 */
it('produit un dump qui se restaure dans une base neuve, même quand Pulse a des lignes', function (): void {
    $base = DB::connection()->getDatabaseName();
    $baseRestauree = $base.'_test_restauration';
    $tablesDePulse = ['pulse_aggregates', 'pulse_entries', 'pulse_values'];

    // Une seconde connexion, hors de la transaction du test : le dump ne voit que
    // des lignes validées, et cette transaction ne voit pas les tables créées
    // après elle (information_schema vide, puis « Table definition has changed »).
    Config::set('database.connections.sauvegarde_temoin', config('database.connections.'.DB::getDefaultConnection()));
    $temoin = DB::connection('sauvegarde_temoin');
    $tablesDe = static fn (string $schema): array => array_column($temoin->select(
        'select table_name as nom from information_schema.tables where table_schema = ? order by nom',
        [$schema],
    ), 'nom');

    expect(array_column($temoin->select(
        "select distinct table_name as nom from information_schema.columns where table_schema = ? and generation_expression <> '' order by nom",
        [$base],
    ), 'nom'))->toBe($tablesDePulse, 'une table hors de Pulse a une colonne générée : le dump du client MariaDB ne la restaurerait pas');

    $dossier = storage_path('framework/testing/sauvegardes-'.uniqid());
    File::ensureDirectoryExists($dossier);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);
    Config::set('backup.backup.temporary_directory', $dossier.'/.temp');
    Config::set('backup.backup.password', 'mot-de-passe-de-test');
    Config::set('backup.notifications.notifications', []);
    // Le paquet fige sa configuration dans un singleton au démarrage : on la refait lire.
    app()->forgetInstance(\Spatie\Backup\Config\Config::class);

    $dumperLance = null;
    Event::listen(DumpingDatabase::class, function (DumpingDatabase $evenement) use (&$dumperLance): void {
        $dumperLance = $evenement->dbDumper;
    });

    $cle = 'GET /témoin-'.uniqid();
    $instant = now()->getTimestamp();

    try {
        $temoin->table('pulse_aggregates')->insert(['bucket' => $instant, 'period' => 60, 'type' => 'slow_request', 'key' => $cle, 'aggregate' => 'count', 'value' => 1]);
        $temoin->table('pulse_entries')->insert(['timestamp' => $instant, 'type' => 'slow_request', 'key' => $cle, 'value' => 123]);
        $temoin->table('pulse_values')->insert(['timestamp' => $instant, 'type' => 'temoin', 'key' => $cle, 'value' => '{}']);

        expect(Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]))->toBe(0, Artisan::output())
            ->and($dumperLance)->toBeInstanceOf(MySql::class);
        assert($dumperLance instanceof MySql);

        $nom = config('backup.backup.name');
        assert(is_string($nom));
        $archives = glob($dossier.'/'.$nom.'/*.zip');
        assert(is_array($archives));
        expect($archives)->toHaveCount(1);
        $zip = new ZipArchive();
        expect($zip->open($archives[0]))->toBeTrue();
        $zip->setPassword('mot-de-passe-de-test');
        $entree = collect(range(0, $zip->numFiles - 1))
            ->map(fn (int $i): string => (string) $zip->getNameIndex($i))
            ->first(fn (string $nom): bool => str_ends_with($nom, '.sql'));
        expect($entree)->toBeString();
        assert(is_string($entree));
        $dump = $zip->getFromName($entree);
        $zip->close();
        expect($dump)->toBeString();
        assert(is_string($dump));

        $temoin->statement("drop database if exists `{$baseRestauree}`");
        $temoin->statement("create database `{$baseRestauree}`");
        $identifiants = $dossier.'/identifiants.cnf';
        File::put($identifiants, $dumperLance->getContentsOfCredentialsFile());

        $restauration = Process::input($dump)->timeout(120)
            ->run(['mysql', '--defaults-extra-file='.$identifiants, '--loose-skip-ssl', $baseRestauree]);

        expect($restauration->exitCode())->toBe(0, $restauration->errorOutput())
            ->and($tablesDe($baseRestauree))->toBe($tablesDe($base))
            ->and($tablesDe($baseRestauree))->toContain('users', 'sets', ...$tablesDePulse)
            ->and($temoin->table($baseRestauree.'.migrations')->count())->toBe($temoin->table('migrations')->count());

        $commande = $dumperLance->getDumpCommand('/tmp/dump.sql', '/tmp/identifiants.cnf');
        foreach ($tablesDePulse as $table) {
            expect($commande)->toContain(" --loose-ignore-table-data={$base}.{$table} ");
        }
        expect($commande)->not->toContain(' --ignore-table-data=');
    } finally {
        $temoin->statement("drop database if exists `{$baseRestauree}`");
        foreach ($tablesDePulse as $table) {
            $temoin->table($table)->where('key', $cle)->delete();
        }
        DB::purge('sauvegarde_temoin');
        File::deleteDirectory($dossier);
    }
});

it('refuse d’écrire une archive sans mot de passe, d’où que vienne la demande', function (): void {
    $dossier = storage_path('framework/testing/sauvegardes-'.uniqid());
    File::ensureDirectoryExists($dossier);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);
    Config::set('backup.backup.temporary_directory', $dossier.'/.temp');
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

/*
 * Un serveur MySQL 9.7 a ses GTID par défaut, journal binaire ou non. Le
 * client `mysqldump` de MySQL (8.0.33 et suivants, 8.0.46 sur les runners de
 * la CI) fait alors précéder l'instantané de `--single-transaction` d'un
 * verrou global tant que `--set-gtid-purged` n'est pas OFF : il faut RELOAD
 * ou FLUSH_TABLES, que le compte de l'application n'a pas, et la sauvegarde
 * tombait sur 9.7.2. Le client MariaDB de l'image ne connaît pas l'option :
 * `loose-` la lui fait ignorer, alors que la clé `mysql_gtid_purged` du
 * paquet l'écrit nue, et il refuserait le dump.
 */
it('dumpe sans verrou global ni GTID, que le client soit celui de MySQL ou de MariaDB', function (): void {
    $dumper = \Spatie\Backup\Tasks\Backup\DbDumperFactory::createFromConnection('mysql');
    assert($dumper instanceof \Spatie\DbDumper\Databases\MySql);

    $commande = $dumper->getDumpCommand('/tmp/dump.sql', '/tmp/identifiants.cnf');

    expect($commande)->toContain(' --loose-set-gtid-purged=OFF ')
        ->and($commande)->not->toContain(' --set-gtid-purged');
});
