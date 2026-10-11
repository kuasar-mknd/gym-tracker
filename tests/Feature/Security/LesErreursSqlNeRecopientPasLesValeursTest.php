<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Models\User;
use BezhanSalleh\FilamentExceptions\Resources\ExceptionResource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;

/*
 * Le message d'une erreur SQL recopiait les valeurs de la requête, et ce
 * message part dans les journaux, dans la table des exceptions du panneau et
 * dans `failed_jobs` (#2032). `mask_bindings_in_exception_messages` le fait
 * finir sur la requête avec ses `?`.
 *
 * Chaque insertion ci-dessous viole une contrainte dont le serveur ne cite pas
 * la valeur : l'option ne touche pas au texte du serveur, et celui d'une clé
 * en double ou d'une valeur mal typée cite la valeur refusée.
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
 * Insère une séance que la base refuse, avec le canari parmi les valeurs.
 *
 * `cle-etrangere` : la séance d'un compte qui n'existe pas. `trop-longue` : un
 * nom plus long que sa colonne, pour un compte qui existe.
 */
function erreursSqlInserer(string $refus, string $canari): void
{
    $seance = match ($refus) {
        'cle-etrangere' => ['user_id' => 0, 'name' => 'Séance orpheline', 'notes' => $canari],
        'trop-longue' => ['user_id' => User::factory()->create()->id, 'name' => $canari.str_repeat('x', 300), 'notes' => null],
        default => throw new InvalidArgumentException("Refus inconnu : {$refus}."),
    };

    DB::table('workouts')->insert([...$seance, 'started_at' => '2026-10-11 08:00:00']);
}

/**
 * L'exception que lève l'insertion refusée.
 */
function erreursSqlExceptionDe(string $refus): QueryException
{
    try {
        erreursSqlInserer($refus, erreursSqlCanari());
    } catch (QueryException $exception) {
        return $exception;
    }

    throw new RuntimeException("L'insertion « {$refus} » n'a pas été refusée.");
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

/**
 * Un job qui échoue sur la même insertion. Il ne porte aucune propriété : sa
 * charge utile, gardée dans `failed_jobs`, ne contient donc pas le canari.
 */
final class ErreursSqlJobQuiInsereUneSeanceOrpheline implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        erreursSqlInserer('cle-etrangere', erreursSqlCanari());
    }
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

it('ne recopie pas les valeurs d’une insertion refusée dans le message de son exception', function (string $refus, string $texteDuServeur): void {
    $exception = erreursSqlExceptionDe($refus);
    $valeurs = array_map(static fn (mixed $valeur): string => is_scalar($valeur) ? (string) $valeur : '', $exception->getBindings());

    expect($exception->getMessage())
        ->not->toContain(erreursSqlCanari())
        ->toStartWith('SQLSTATE[')
        ->toContain($texteDuServeur)
        ->toMatch('/, SQL: insert into `workouts` \([^)]+\) values \(\?(, \?)+\)\)$/')
        // Le code qui rattrape l'exception lit toujours les valeurs : seul le
        // message les tait.
        ->and(implode("\n", $valeurs))->toContain(erreursSqlCanari());
})->with([
    'une clé étrangère sans ligne' => ['cle-etrangere', 'a foreign key constraint fails'],
    'une valeur plus longue que sa colonne' => ['trop-longue', "Data too long for column 'name'"],
]);

/*
 * La page de l'exception dans le panneau en montre le message et la pile, que
 * le panneau affiche sans arguments quand l'exception n'en a pas gardé.
 */
it('ne laisse la valeur ni dans la table des exceptions, ni dans sa page du panneau, ni dans le journal quand une requête échoue sur l’insertion', function (): void {
    $journal = erreursSqlJournalDansUnFichier();

    Route::post('/_seance-orpheline', static function (): never {
        erreursSqlInserer('cle-etrangere', erreursSqlCanari());

        throw new RuntimeException("L'insertion n'a pas été refusée.");
    });

    $this->post('/_seance-orpheline')->assertStatus(500);

    $ecrit = erreursSqlLireEtEffacer($journal);
    $exception = ExceptionEnregistree::query()->sole();
    $page = (string) actingAs(FilamentAdminPanel::admin(['ViewAny:ExceptionEnregistree', 'View:ExceptionEnregistree']), 'admin')
        ->get(ExceptionResource::getUrl('view', ['record' => $exception], panel: 'admin'))
        ->assertOk()
        ->baseResponse
        ->getContent();

    expect($exception->type)->toBe(QueryException::class)
        ->and($page)->toContain('a foreign key constraint fails')
        ->and($ecrit)->toContain('a foreign key constraint fails')
        ->and(erreursSqlTracesQuiRecopientLeCanari([
            'la table des exceptions' => erreursSqlLignesDe('filament_exceptions_table'),
            'la page de l’exception dans le panneau' => $page,
            'le journal' => $ecrit,
        ]))->toBe([]);
});

it('ne laisse la valeur ni dans failed_jobs, ni dans la table des exceptions, ni dans le journal quand un job échoue sur l’insertion', function (): void {
    $journal = erreursSqlJournalDansUnFichier();

    dispatch(new ErreursSqlJobQuiInsereUneSeanceOrpheline())->onConnection('database');

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    $ecrit = erreursSqlLireEtEffacer($journal);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toBeString()->toContain('a foreign key constraint fails')
        ->and(DB::table('filament_exceptions_table')->where('type', QueryException::class)->count())->toBe(1)
        ->and($ecrit)->toContain('a foreign key constraint fails')
        ->and(erreursSqlTracesQuiRecopientLeCanari([
            'failed_jobs' => erreursSqlLignesDe('failed_jobs'),
            'la table des exceptions' => erreursSqlLignesDe('filament_exceptions_table'),
            'le journal' => $ecrit,
        ]))->toBe([]);
});
