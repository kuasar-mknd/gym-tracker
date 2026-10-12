<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Support\BaseDeDonnees\ConnexionMariaDb;
use App\Support\BaseDeDonnees\ConnexionMySql;
use BezhanSalleh\FilamentExceptions\Resources\ExceptionResource;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\FilamentAdminPanel;
use Tests\Support\JobQuiEcritUneLigneRefusee;

use function Pest\Laravel\actingAs;

/*
 * Le message d'une erreur SQL recopiait les valeurs de la requête, et ce
 * message part dans les journaux, dans la table des exceptions du panneau et
 * dans `failed_jobs` (#2032). `mask_bindings_in_exception_messages` le fait
 * finir sur la requête avec ses `?`. Le texte du serveur, qui le précède,
 * cite lui aussi la valeur d'une clé en double, d'un entier ou d'une date mal
 * formés : les connexions `ConnexionMySql` et `ConnexionMariaDb` la
 * remplacent par `'?'` (`TexteDuServeurSansValeurs`).
 *
 * Chaque refus ci-dessous passe par une vraie écriture : deux dont le serveur
 * ne cite pas la valeur, cinq où il la cite. Deux de ces valeurs portent des
 * apostrophes et imitent la fin d'un texte du serveur : celle d'une clé en
 * double, que le serveur cite avant le nom de l'index, et celle d'une
 * comparaison, qu'il cite en dernier.
 *
 * La pile est réglée comme dans l'image, d'après `docker/php-prod.ini` : sans
 * `zend.exception_ignore_args`, ses arguments recopieraient la valeur que
 * l'option tait dans le message (`LesPilesNeGardentPasLeursArgumentsTest`).
 */

/**
 * La valeur repérable. Courte : une pile écrite en texte ne garde que les
 * quinze premiers caractères d'un argument.
 */
function erreursSqlCanari(): string
{
    return 'canari-2032-q7';
}

/**
 * Fait refuser une écriture par la base, avec le canari parmi les valeurs.
 *
 * `cle-etrangere` : la séance d'un compte qui n'existe pas. `trop-longue` : un
 * nom plus long que sa colonne. `entier-mal-forme` : le canari pour
 * identifiant du compte. `date-mal-formee` : le canari pour date de début.
 * `cle-en-double` : un compte dont l'adresse est déjà prise.
 * `cle-en-double-rusee` : la même, avec une adresse qui porte des apostrophes
 * et la fin du texte du serveur, pour qu'un masque qui couperait aux
 * apostrophes laisse sortir le canari. `comparaison-rusee` : un compte
 * modifié d'après un identifiant qui n'est pas un entier et finit comme la
 * queue d'une clé en double, pour qu'un masque qui garderait cette queue
 * hors de sa forme laisse sortir le canari.
 */
function erreursSqlEcrire(string $refus, string $canari): void
{
    match ($refus) {
        'cle-etrangere' => erreursSqlInsererUneSeance(['user_id' => 0, 'notes' => $canari]),
        'trop-longue' => erreursSqlInsererUneSeance(['user_id' => User::factory()->create()->id, 'name' => $canari.str_repeat('x', 300)]),
        'entier-mal-forme' => erreursSqlInsererUneSeance(['user_id' => $canari]),
        'date-mal-formee' => erreursSqlInsererUneSeance(['user_id' => User::factory()->create()->id, 'started_at' => $canari]),
        'cle-en-double' => erreursSqlInsererDeuxComptesA("{$canari}@example.org"),
        'cle-en-double-rusee' => erreursSqlInsererDeuxComptesA("o'{$canari}' for key '{$canari}@example.org"),
        'comparaison-rusee' => erreursSqlRenommerLeCompte("abc' for key '{$canari}"),
        default => throw new InvalidArgumentException("Refus inconnu : {$refus}."),
    };
}

/**
 * Insère une séance, les colonnes données par-dessus celles d'une séance
 * ordinaire.
 *
 * @param  array<string, mixed>  $colonnes
 */
function erreursSqlInsererUneSeance(array $colonnes): bool
{
    return DB::table('workouts')->insert([...['name' => 'Séance', 'notes' => null, 'started_at' => '2026-10-11 08:00:00'], ...$colonnes]);
}

/**
 * Insère deux comptes à la même adresse : le second est refusé.
 *
 * Le premier prend l'adresse en capitales. La collation de la colonne la
 * tient pour la même, et le serveur cite la valeur refusée, en minuscules :
 * le canari n'est ainsi que dans la requête refusée. Avec `APP_DEBUG` (la CI
 * part de `.env.example`), Laravel garde les requêtes réussies, valeurs
 * comprises, et le paquet des exceptions les enregistre (`query`,
 * `markdown`) : un canari écrit par une requête réussie s'y trouverait, quoi
 * que fasse le masque.
 */
function erreursSqlInsererDeuxComptesA(string $adresse): bool
{
    DB::table('users')->insert(['name' => 'Premier', 'email' => mb_strtoupper($adresse)]);

    return DB::table('users')->insert(['name' => 'Doublon', 'email' => $adresse]);
}

/**
 * Renomme le compte dont l'identifiant vaut celui donné. Le serveur refuse de
 * convertir en entier un identifiant qui n'en est pas un, et cite la valeur à
 * la fin de son texte (1292). Un compte existe : sans ligne à comparer, le
 * serveur ne refuserait rien.
 */
function erreursSqlRenommerLeCompte(string $identifiant): int
{
    User::factory()->create();

    return DB::table('users')->where('id', $identifiant)->update(['name' => 'Renommé']);
}

/**
 * Chaque refus, le texte du serveur qu'en garde le message et la classe de
 * l'exception.
 *
 * @return array<string, array{string, string, class-string<QueryException>}>
 */
function erreursSqlRefus(): array
{
    return [
        'une clé étrangère sans ligne' => ['cle-etrangere', 'a foreign key constraint fails', QueryException::class],
        'une valeur plus longue que sa colonne' => ['trop-longue', "Data too long for column 'name' at row 1", QueryException::class],
        'un entier mal formé' => ['entier-mal-forme', "1366 Incorrect integer value: '?' for column 'user_id' at row 1", QueryException::class],
        'une date mal formée' => ['date-mal-formee', "1292 Incorrect datetime value: '?' for column 'started_at' at row 1", QueryException::class],
        'une clé en double' => ['cle-en-double', "1062 Duplicate entry '?' for key 'users.users_email_unique'", UniqueConstraintViolationException::class],
        'une clé en double dont la valeur imite le serveur' => ['cle-en-double-rusee', "1062 Duplicate entry '?' for key 'users.users_email_unique'", UniqueConstraintViolationException::class],
        'une comparaison dont la valeur imite une clé en double' => ['comparaison-rusee', "1292 Truncated incorrect INTEGER value: '?' (Connection: mysql", QueryException::class],
    ];
}

/**
 * L'exception que lève l'écriture refusée.
 */
function erreursSqlExceptionDe(string $refus): QueryException
{
    try {
        erreursSqlEcrire($refus, erreursSqlCanari());
    } catch (QueryException $exception) {
        return $exception;
    }

    throw new RuntimeException("L'écriture « {$refus} » n'a pas été refusée.");
}

/**
 * Le journal par défaut, détourné vers un fichier neuf, écrit comme la
 * production écrit le sien (LineFormatter, pile comprise).
 */
function erreursSqlJournalDansUnFichier(): string
{
    $fichier = sys_get_temp_dir().'/erreurs-sql-'.bin2hex(random_bytes(6)).'.log';

    Config::set('logging.channels.erreurs_sql', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'erreurs_sql');

    return $fichier;
}

/**
 * Le contenu d'un fichier de journal, qu'on efface ensuite.
 */
function erreursSqlLireEtEffacer(string $fichier): string
{
    $contenu = is_file($fichier) ? (string) file_get_contents($fichier) : '';

    if (is_file($fichier)) {
        unlink($fichier);
    }

    return $contenu;
}

/**
 * Chaque ligne d'une table, toutes colonnes, en un seul texte.
 */
function erreursSqlLignesDe(string $table): string
{
    return (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Les traces, parmi celles données, qui contiennent le canari.
 *
 * @param  array<string, string>  $traces
 * @return list<string>
 */
function erreursSqlTracesQuiRecopientLeCanari(array $traces): array
{
    return array_keys(array_filter($traces, static fn (string $texte): bool => str_contains($texte, erreursSqlCanari())));
}

beforeEach(function (): void {
    $production = parse_ini_file(base_path('docker/php-prod.ini'));
    $ignorerLesArguments = is_array($production) ? ($production['zend.exception_ignore_args'] ?? null) : null;

    expect($ignorerLesArguments)->toBeString('docker/php-prod.ini ne règle pas zend.exception_ignore_args.');

    ini_set('zend.exception_ignore_args', is_string($ignorerLesArguments) ? $ignorerLesArguments : '0');
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
});

it('masque les valeurs des requêtes dans les messages d’erreur des connexions mysql et mariadb', function (): void {
    expect(config('database.connections.mysql.mask_bindings_in_exception_messages'))->toBeTrue()
        ->and(config('database.connections.mariadb.mask_bindings_in_exception_messages'))->toBeTrue();
});

it('ouvre les connexions mysql et mariadb par les classes qui masquent le texte du serveur', function (): void {
    try {
        expect(DB::connection('mysql'))->toBeInstanceOf(ConnexionMySql::class)
            ->and(DB::connection('mariadb'))->toBeInstanceOf(ConnexionMariaDb::class);
    } finally {
        DB::purge('mariadb');
    }
});

it('ne recopie pas les valeurs d’une écriture refusée dans le message de son exception', function (string $refus, string $texteDuServeur, string $classe): void {
    $exception = erreursSqlExceptionDe($refus);
    $valeurs = array_map(static fn (mixed $valeur): string => is_scalar($valeur) ? (string) $valeur : '', $exception->getBindings());

    expect($exception::class)->toBe($classe)
        ->and($exception->getMessage())
        ->not->toContain(erreursSqlCanari())
        ->toStartWith('SQLSTATE[')
        ->toContain($texteDuServeur)
        ->toMatch('/, SQL: (insert into `(workouts|users)` \([^)]+\) values \(\?(, \?)+\)|update `users` set `name` = \? where `id` = \?)\)$/')
        ->and((string) $exception)->not->toContain(erreursSqlCanari())
        // Le code qui rattrape l'exception lit toujours les valeurs : seul le
        // message les tait.
        ->and(implode("\n", $valeurs))->toContain(erreursSqlCanari());
})->with(erreursSqlRefus(...));

/*
 * Laravel relit le texte du serveur pour reconnaître une clé en double et en
 * tirer l'index : `CreateSetAction` et `PersonalRecordService` rattrapent
 * `UniqueConstraintViolationException`.
 */
it('garde ce que Laravel lit dans l’erreur d’une clé en double', function (): void {
    $exception = erreursSqlExceptionDe('cle-en-double-rusee');
    $erreurDePdo = $exception->getPrevious();

    expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($exception instanceof UniqueConstraintViolationException ? $exception->index : null)->toBe('users_email_unique')
        ->and($exception->getCode())->toBe('23000')
        ->and($exception->errorInfo)->toBe(['23000', 1062, "Duplicate entry '?' for key 'users.users_email_unique'"])
        ->and($erreurDePdo)->toBeInstanceOf(PDOException::class)
        ->and($erreurDePdo?->getMessage())->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_email_unique'")
        ->and($erreurDePdo?->getPrevious())->toBeNull();
});

/*
 * La page de l'exception dans le panneau en montre le message et la pile, que
 * le panneau affiche sans arguments quand l'exception n'en a pas gardé.
 */
it('ne laisse la valeur ni dans la table des exceptions, ni dans sa page du panneau, ni dans le journal quand une requête échoue sur l’écriture', function (string $refus, string $texteDuServeur, string $classe): void {
    $journal = erreursSqlJournalDansUnFichier();

    Route::post('/_ecriture-refusee', static function () use ($refus): never {
        erreursSqlEcrire($refus, erreursSqlCanari());

        throw new RuntimeException("L'écriture n'a pas été refusée.");
    });

    $this->post('/_ecriture-refusee')->assertStatus(500);

    $ecrit = erreursSqlLireEtEffacer($journal);
    $exception = ExceptionEnregistree::query()->sole();
    $page = (string) actingAs(FilamentAdminPanel::admin(['ViewAny:ExceptionEnregistree', 'View:ExceptionEnregistree']), 'admin')
        ->get(ExceptionResource::getUrl('view', ['record' => $exception], panel: 'admin'))
        ->assertOk()
        ->baseResponse
        ->getContent();

    expect(erreursSqlTracesQuiRecopientLeCanari([
        'la table des exceptions' => erreursSqlLignesDe('filament_exceptions_table'),
        'la page de l’exception dans le panneau' => $page,
        'le journal' => $ecrit,
    ]))->toBe([])
        ->and($exception->type)->toBe($classe)
        ->and(html_entity_decode($page, ENT_QUOTES | ENT_HTML5))->toContain($texteDuServeur)
        ->and($ecrit)->toContain($texteDuServeur);
})->with(erreursSqlRefus(...));

it('ne laisse la valeur ni dans failed_jobs, ni dans la table des exceptions, ni dans le journal quand un job échoue sur l’écriture', function (string $refus, string $texteDuServeur, string $classe): void {
    $journal = erreursSqlJournalDansUnFichier();

    dispatch(new JobQuiEcritUneLigneRefusee($refus))->onConnection('database');

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    $ecrit = erreursSqlLireEtEffacer($journal);

    expect(erreursSqlTracesQuiRecopientLeCanari([
        'failed_jobs' => erreursSqlLignesDe('failed_jobs'),
        'la table des exceptions' => erreursSqlLignesDe('filament_exceptions_table'),
        'le journal' => $ecrit,
    ]))->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toBeString()->toContain($texteDuServeur)
        ->and(DB::table('filament_exceptions_table')->where('type', $classe)->count())->toBe(1)
        ->and($ecrit)->toContain($texteDuServeur);
})->with(erreursSqlRefus(...));
