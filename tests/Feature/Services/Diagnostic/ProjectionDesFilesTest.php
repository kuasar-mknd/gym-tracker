<?php

declare(strict_types=1);

use App\Jobs\SyncUserGoals;
use App\Services\Diagnostic\ExpurgeLeTexte;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\EtatDeHorizon;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Tests\Support\JobDuDiagnosticQuiEchoue;

/*
 * Les files et les jobs échoués vus par le diagnostic (#2032) : l'état
 * d'Horizon, les échecs des trente derniers jours groupés par classe de job
 * et d'exception ; au détail, la pile sans arguments et, sous `TexteBrut`,
 * le message. Jamais la charge utile.
 */

/**
 * Un échec de job, aux colonnes données par-dessus des valeurs ordinaires.
 * Son exception est écrite comme PHP l'écrit quand elle en enveloppe une
 * autre : l'exception précédente d'abord, puis « Next » et celle que le job
 * a levée.
 *
 * @param  array<string, mixed>  $colonnes
 */
function filesDiagnosticEchec(array $colonnes = []): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => SyncUserGoals::class, 'data' => ['command' => 'charge-utile-canari']]),
        'exception' => 'PDOException: SQLSTATE[23000]: boum in '.base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php').":589\n"
            ."Stack trace:\n"
            .'#0 '.base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php')."(589): PDOStatement->execute()\n"
            ."#1 {main}\n\n"
            .'Next Illuminate\\Database\\QueryException: SQLSTATE[23000]: boum (Connection: mysql, SQL: insert into `users` values (charge-utile-canari)) in '.base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php').":838\n"
            ."Stack trace:\n"
            .'#0 '.base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php')."(794): Illuminate\\Database\\Connection->runQueryCallback('insert into `us...', Array, Object(Closure))\n"
            .'#1 '.base_path('app/Jobs/SyncUserGoals.php')."(40): Illuminate\\Database\\Connection->insert('argument-canari')\n"
            ."#2 [internal function]: App\\Jobs\\SyncUserGoals->handle()\n"
            .'#3 {main}',
        'failed_at' => now(),
        ...$colonnes,
    ]);

    return $uuid;
}

function filesDiagnosticFenetre(): FenetreDeDiagnostic
{
    return FenetreDeDiagnostic::depuis(null, now());
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
    test()->mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([]);
    test()->mock(WorkloadRepository::class)->shouldReceive('get')->andReturn([]);
});

it('groupe les échecs des trente derniers jours par classe de job et d’exception, sans la charge utile', function (): void {
    Carbon::setTestNow('2026-09-12 09:59:59');
    filesDiagnosticEchec();
    Carbon::setTestNow('2026-09-12 10:00:00');
    $premier = filesDiagnosticEchec();
    Carbon::setTestNow('2026-10-12 09:00:00');
    $dernier = filesDiagnosticEchec();
    $autre = filesDiagnosticEchec(['exception' => 'RuntimeException: autre']);
    Carbon::setTestNow('2026-10-12 10:00:00');

    $liste = new ProjectionDesFiles()->liste(filesDiagnosticFenetre());
    $echecs = $liste['echecs'];

    expect($echecs)->toBe([
        [
            'job' => SyncUserGoals::class,
            'exception' => \Illuminate\Database\QueryException::class,
            'dernier_uuid' => $dernier,
            'nombre' => 2,
            'premiere_le' => '2026-09-12T10:00:00+02:00',
            'derniere_le' => '2026-10-12T09:00:00+02:00',
            'dernier_id' => DB::table('failed_jobs')->where('uuid', $dernier)->value('id'),
        ],
        [
            'job' => SyncUserGoals::class,
            'exception' => 'RuntimeException',
            'dernier_uuid' => $autre,
            'nombre' => 1,
            'premiere_le' => '2026-10-12T09:00:00+02:00',
            'derniere_le' => '2026-10-12T09:00:00+02:00',
            'dernier_id' => DB::table('failed_jobs')->where('uuid', $autre)->value('id'),
        ],
    ])
        ->and([$liste['lignes_lues'], $liste['echantillon'], $liste['tronque']])->toBe([3, false, false])
        ->and(json_encode($liste))->not->toContain('canari')
        ->and($premier)->not->toBe($dernier);
});

it('rend le détail d’un échec, la pile sans arguments, et le message sous TexteBrut seulement', function (): void {
    $uuid = filesDiagnosticEchec(['queue' => 'notifications']);

    $sans = new ProjectionDesFiles()->detail($uuid, filesDiagnosticFenetre(), false);
    $avec = new ProjectionDesFiles()->detail($uuid, filesDiagnosticFenetre(), true);

    expect($sans)->toBe([
        'uuid' => $uuid,
        'job' => SyncUserGoals::class,
        'file' => 'notifications',
        'echoue_le' => '2026-10-12T10:00:00+02:00',
        'exception' => \Illuminate\Database\QueryException::class,
        'pile' => [
            ['fichier' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'ligne' => 794, 'appel' => 'Illuminate\Database\Connection->runQueryCallback'],
            ['fichier' => 'app/Jobs/SyncUserGoals.php', 'ligne' => 40, 'appel' => 'Illuminate\Database\Connection->insert'],
        ],
    ])
        ->and($avec['non_fiable'] ?? null)->toEqual([
            'message' => new TexteNonFiable(
                'SQLSTATE[23000]: boum (Connection: mysql, SQL: insert into `users` values (charge-utile-canari))',
                ProjectionDesFiles::BUDGET_DU_MESSAGE,
            ),
        ]);
});

/*
 * Un vrai échec, par `queue:work` : l'écriture refusée lève une
 * `PDOException`, que Laravel enveloppe. `failed_jobs` écrit d'abord la
 * précédente, puis « Next » et celle que le job a levée ; le diagnostic
 * range l'échec sous celle-ci, comme la table des exceptions.
 */
it('range un vrai échec sous l’exception que le job a levée, pas sous celle qu’elle enveloppe', function (): void {
    dispatch(new JobDuDiagnosticQuiEchoue('Premier compte', 'Second compte'))->onConnection('database');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    $brut = ValeurLue::texte(DB::table('failed_jobs')->value('exception'));
    $liste = new ProjectionDesFiles()->liste(filesDiagnosticFenetre());
    $detail = new ProjectionDesFiles()->detail(ValeurLue::texte(DB::table('failed_jobs')->value('uuid')), filesDiagnosticFenetre(), true);
    $message = data_get($detail, 'non_fiable.message');

    expect($brut)->toStartWith('PDOException: SQLSTATE[23000]: ')
        ->and($brut)->toContain("\n\nNext ".UniqueConstraintViolationException::class.': SQLSTATE[23000]: ')
        ->and(array_map(static fn (array $echec): array => [$echec['job'], $echec['exception'], $echec['nombre']], $liste['echecs']))
        ->toBe([[JobDuDiagnosticQuiEchoue::class, UniqueConstraintViolationException::class, 1]])
        ->and($detail['exception'] ?? null)->toBe(UniqueConstraintViolationException::class)
        ->and(data_get($detail, 'pile.0.appel'))->toBe('Illuminate\Database\Connection->runQueryCallback')
        ->and($message)->toBeInstanceOf(TexteNonFiable::class)
        ->and(new ExpurgeLeTexte()($message instanceof TexteNonFiable ? $message->brut : '')->texte)
        ->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_email_unique' (insert `users`)");
});

it('ne rend pas le détail d’un échec de plus de trente jours, ni d’un échec inconnu', function (): void {
    Carbon::setTestNow('2026-09-12 09:59:59');
    $ancien = filesDiagnosticEchec();
    Carbon::setTestNow('2026-10-12 10:00:00');

    expect(new ProjectionDesFiles()->detail($ancien, filesDiagnosticFenetre(), true))->toBeNull()
        ->and(new ProjectionDesFiles()->detail((string) Str::uuid(), filesDiagnosticFenetre(), true))->toBeNull();
});

it('ne lit que les 5 000 derniers échecs, et le dit', function (): void {
    $lignes = array_map(static fn (int $rang): array => [
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: boum',
        'failed_at' => now(),
    ], range(1, LecturesBornees::LIGNES_AU_PLUS + 1));

    foreach (array_chunk($lignes, 1_000) as $paquet) {
        DB::table('failed_jobs')->insert($paquet);
    }

    $liste = new ProjectionDesFiles()->liste(filesDiagnosticFenetre());

    expect([$liste['lignes_lues'], $liste['echantillon'], $liste['tronque'], $liste['echecs'][0]['nombre'] ?? null])
        ->toBe([LecturesBornees::LIGNES_AU_PLUS, true, false, LecturesBornees::LIGNES_AU_PLUS]);
});

it('dit l’état d’Horizon : arrêté, en pause, actif, et ses files', function (): void {
    expect(new EtatDeHorizon()())->toBe(['statut' => 'arrete', 'processus' => 0, 'files' => []]);

    test()->mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([(object) ['status' => 'running'], (object) ['status' => 'paused']]);
    test()->mock(WorkloadRepository::class)->shouldReceive('get')->andReturn([
        ['name' => 'redis:default', 'length' => 4, 'wait' => 12, 'processes' => 3],
        ['name' => 'redis:notifications', 'length' => 0, 'wait' => 0, 'processes' => 2],
    ]);

    expect(new EtatDeHorizon()())->toBe([
        'statut' => 'en_pause',
        'processus' => 5,
        'files' => [
            ['nom' => 'redis:default', 'taille' => 4, 'attente_en_secondes' => 12, 'processus' => 3],
            ['nom' => 'redis:notifications', 'taille' => 0, 'attente_en_secondes' => 0, 'processus' => 2],
        ],
    ]);

    test()->mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([(object) ['status' => 'running']]);

    expect(new EtatDeHorizon()()['statut'])->toBe('actif');
});

it('dit Horizon illisible quand son magasin ne répond pas, sans lever', function (): void {
    test()->mock(MasterSupervisorRepository::class)->shouldReceive('all')->andThrow(new RuntimeException('cache injoignable'));

    expect(new EtatDeHorizon()())->toBe(['statut' => 'illisible', 'processus' => null, 'files' => []])
        ->and(new ProjectionDesFiles()->liste(filesDiagnosticFenetre())['horizon']['statut'])->toBe('illisible');
});
