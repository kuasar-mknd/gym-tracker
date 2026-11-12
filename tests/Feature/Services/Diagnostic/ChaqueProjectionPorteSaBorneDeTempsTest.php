<?php

declare(strict_types=1);

use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\TachePlanifiee;
use App\Models\User;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\AgeDuPlusAncienEnAttente;
use App\Services\Diagnostic\Projections\EtatDeHorizon;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDeLaSante;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use App\Services\Diagnostic\Projections\ProjectionDesVersions;
use App\Services\Diagnostic\ValeurLue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/*
 * Chaque projection du diagnostic qui lit la base le fait sous la borne de
 * temps de MySQL (#2032) : toute requête envoyée porte l'indication
 * `MAX_EXECUTION_TIME` juste après `select`. Celles qui ne lisent que le
 * cache ou Redis (santé, versions, Horizon, âge du plus ancien job) n'envoient
 * aucun SQL : une projection qui se mettrait à lire la base sans la borne, ou
 * une qui la perdrait par un `count()` ou un second `select()`, fait tomber
 * ce test.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    $compte = User::factory()->create();
    $groupe = ExceptionEnregistree::query()->create(['type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => 'f', 'line' => 1, 'trace' => [], 'method' => 'GET', 'path' => '/']);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException: boum', 'failed_at' => now()]);
    ErreurNavigateur::query()->create(['user_id' => $compte->id, 'type' => 'error', 'message' => 'm', 'url' => '/', 'empreinte' => md5('e')]);
    $tache = TachePlanifiee::query()->create(['name' => 'b:echouee', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5, 'last_failed_at' => now()]);
    DB::table('monitored_scheduled_task_log_items')->insert(['monitored_scheduled_task_id' => $tache->id, 'type' => 'failed', 'meta' => json_encode(['failure_message' => 'boum']), 'created_at' => now(), 'updated_at' => now()]);
    test()->groupe = $groupe->id;
    test()->tache = $tache->id;
    test()->mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([]);
    test()->mock(WorkloadRepository::class)->shouldReceive('get')->andReturn([['name' => 'default', 'length' => 1, 'wait' => 1, 'processes' => 1]]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Le SQL que la lecture envoie.
 *
 * @return list<string>
 */
function projectionLesRequetes(Closure $lecture): array
{
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    $lecture();

    return $requetes;
}

/**
 * Chaque lecture d'une projection qui lit la base, liste et détails.
 */
function projectionLire(string $projection, int $groupe, int $tache): void
{
    $fenetre = FenetreDeDiagnostic::jours(30, now());

    match ($projection) {
        'exceptions' => (static function () use ($fenetre, $groupe): void {
            $lue = new ProjectionDesExceptions();
            $lue->liste($fenetre);
            $lue->detail($groupe, $fenetre, true);
            $lue->occurrences($groupe, null, $fenetre);
        })(),
        'files' => (static function () use ($fenetre): void {
            $lue = new ProjectionDesFiles();
            $lue->liste($fenetre);
            $lue->derniers($fenetre, true);
            $lue->detail((string) Str::uuid(), $fenetre, true);
        })(),
        'tâches planifiées' => (static function () use ($fenetre, $tache): void {
            $lue = new ProjectionDesTachesPlanifiees();
            $lue->liste($fenetre);
            $lue->detail($tache, $fenetre, true);
        })(),
        default => (static function () use ($fenetre): void {
            $lue = new ProjectionDesErreursNavigateur();
            $lue->liste($fenetre);
            $lue->detail(md5('e'), $fenetre, true);
        })(),
    };
}

it('envoie chaque lecture de la base sous la borne de temps de MySQL', function (string $projection): void {
    $groupe = ValeurLue::entier(test()->groupe) ?? 0;
    $tache = ValeurLue::entier(test()->tache) ?? 0;
    $requetes = projectionLesRequetes(static fn () => projectionLire($projection, $groupe, $tache));
    $indice = '/*+ MAX_EXECUTION_TIME('.LecturesBornees::MILLISECONDES_AU_PLUS.') */';

    expect($requetes)->not->toBe([])
        ->and(array_filter($requetes, static fn (string $sql): bool => ! str_starts_with($sql, 'select '.$indice.' ')))->toBe([]);
})->with(['exceptions', 'files', 'tâches planifiées', 'erreurs navigateur']);

it('n’envoie aucun SQL quand elle ne lit que le cache ou Redis', function (): void {
    $fenetre = FenetreDeDiagnostic::jours(30, now());

    $requetes = projectionLesRequetes(static function () use ($fenetre): void {
        $sante = new ProjectionDeLaSante();
        $sante->liste($fenetre);
        $sante->detail($fenetre, true);
        $versions = new ProjectionDesVersions();
        $versions->liste();
        $versions->application();
        new EtatDeHorizon()();
        new AgeDuPlusAncienEnAttente()('default,mails', now()->getTimestamp());
    });

    expect($requetes)->toBe([]);
});

it('garde l’indication quand la lecture se fait par clef, avec un nom de table qualifié et une jointure', function (): void {
    $requetes = projectionLesRequetes(static function (): void {
        new ProjectionDesErreursNavigateur()->detail(md5('e'), FenetreDeDiagnostic::jours(30, now()), true);
    });

    expect($requetes)->toHaveCount(2)
        ->and($requetes[0])->toStartWith('select /*+ MAX_EXECUTION_TIME(2000) */ `e`.`id`, `e`.`user_id`')
        ->and($requetes[0])->toContain('inner join `users` as `u`')
        ->and($requetes[1])->toStartWith('select /*+ MAX_EXECUTION_TIME(2000) */ `message`, `url`, `pile` from `erreurs_navigateur` where `id` = ? limit 1');
});
