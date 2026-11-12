<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Services\Diagnostic\ComptesSur7Jours;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\NullHandler;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\get;

/*
 * Les comptes sur sept jours de `GET /diagnostic/v1/etat` (#2032) : un nombre
 * par source, lu sous la borne de temps et de lignes, gardé soixante secondes
 * sous une clef par source (jamais par jeton), et rendu à qui a la portée de
 * la source seulement.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    config()->set('logging.channels.diagnostic', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    config()->set('logging.channels.diagnostic_refus', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    Cache::flush();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  list<string>  $portees
 * @return array{jeton: JetonDeDiagnostic, entete: string}
 */
function etatComptesUnJeton(array $portees): array
{
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $identifiant = Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT));
    $secret = Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET);

    $jeton = JetonDeDiagnostic::factory()->create([
        'identifiant' => $identifiant,
        'empreinte' => JetonDeDiagnostic::empreinteDe($secret),
        'portees' => $portees,
        'cree_par_admin_id' => $admin->id,
    ]);

    return ['jeton' => $jeton, 'entete' => 'Bearer '.JetonDeDiagnostic::composer($identifiant, $secret)];
}

/**
 * @return TestResponse<Response>
 */
function etatComptesAppeler(string $entete): TestResponse
{
    return get('/diagnostic/v1/etat', ['Accept' => 'application/json', 'Authorization' => $entete]);
}

/**
 * Des jobs échoués, des échecs de tâche et des erreurs du navigateur, aux dates données.
 *
 * @param  list<int>  $joursDesJobs
 */
function etatComptesPlanter(array $joursDesJobs): void
{
    foreach ($joursDesJobs as $jours) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\Un"}',
            'exception' => 'RuntimeException: a', 'failed_at' => now()->subDays($jours),
        ]);
    }

    $tache = DB::table('monitored_scheduled_tasks')->insertGetId([
        'name' => 'backup:run', 'type' => 'command', 'cron_expression' => '30 2 * * *', 'grace_time_in_minutes' => 10,
        'created_at' => now()->subDays(40), 'updated_at' => now(),
    ]);
    foreach ([['failed', 1], ['failed', 3], ['failed', 9], ['finished', 1]] as [$type, $jours]) {
        DB::table('monitored_scheduled_task_log_items')->insert([
            'monitored_scheduled_task_id' => $tache, 'type' => $type, 'meta' => '{}', 'created_at' => now()->subDays($jours), 'updated_at' => now()->subDays($jours),
        ]);
    }
}

it('compte sur sept jours chaque source que les portées du jeton ouvrent, et nulle autre', function (): void {
    etatComptesPlanter([1, 6, 8, 20]);
    $tout = etatComptesUnJeton(['sante', 'exceptions', 'files', 'erreurs-navigateur']);
    $seulementLesFiles = etatComptesUnJeton(['files']);

    $reponse = etatComptesAppeler($tout['entete'])->assertOk();
    $restreinte = etatComptesAppeler($seulementLesFiles['entete'])->assertOk();

    expect($reponse->json('donnees.files.sur_7_jours'))->toBe(['total' => 2, 'plafonne' => false])
        ->and($reponse->json('donnees.echecs_de_taches_sur_7_jours'))->toBe(['total' => 2, 'plafonne' => false])
        ->and($reponse->json('donnees.exceptions.sur_7_jours'))->toBe(['total' => 0, 'plafonne' => false])
        ->and($reponse->json('donnees.erreurs_navigateur.sur_7_jours'))->toBe(['total' => 0, 'plafonne' => false])
        ->and($restreinte->json('donnees.files.sur_7_jours.total'))->toBe(2)
        ->and($restreinte->json('donnees'))->not->toHaveKeys(['exceptions', 'erreurs_navigateur', 'echecs_de_taches_sur_7_jours']);
});

it('garde chaque compte soixante secondes sous une clef par source, jamais par jeton ni par réponse', function (): void {
    etatComptesPlanter([1]);
    $premier = etatComptesUnJeton(['files', 'sante']);
    $second = etatComptesUnJeton(['files']);

    etatComptesAppeler($premier['entete'])->assertOk();

    expect(Cache::get(ComptesSur7Jours::CLEF.'files'))->toBe(['total' => 1, 'plafonne' => false])
        ->and(Cache::has(ComptesSur7Jours::CLEF.'sante'))->toBeFalse()
        ->and(Cache::has(ComptesSur7Jours::CLEF.'exceptions'))->toBeFalse();

    etatComptesPlanter([2]);
    $lu = etatComptesAppeler($second['entete'])->json('donnees.files.sur_7_jours.total');
    Carbon::setTestNow(now()->addSeconds(ComptesSur7Jours::SECONDES_EN_CACHE + 1));
    $relu = etatComptesAppeler($second['entete'])->json('donnees.files.sur_7_jours.total');

    expect($lu)->toBe(1)
        ->and($relu)->toBe(2);
});

it('ne range en cache que des nombres, jamais un texte de la source', function (): void {
    etatComptesPlanter([1]);
    $cree = etatComptesUnJeton(['sante', 'exceptions', 'files', 'erreurs-navigateur']);

    etatComptesAppeler($cree['entete'])->assertOk();

    foreach (['exceptions', 'files', 'erreurs-navigateur'] as $source) {
        $valeur = (array) Cache::get(ComptesSur7Jours::CLEF.$source);
        expect(array_keys($valeur))->toBe(['total', 'plafonne'])
            ->and(is_int($valeur['total'] ?? null))->toBeTrue()
            ->and(is_bool($valeur['plafonne'] ?? null))->toBeTrue();
    }
});

it('plafonne le compte à 5 000 et le dit', function (): void {
    $lignes = [];
    foreach (range(1, 5_001) as $numero) {
        $lignes[] = [
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}',
            'exception' => 'RuntimeException: a', 'failed_at' => now()->subHour(),
        ];
    }
    foreach (array_chunk($lignes, 1_000) as $lot) {
        DB::table('failed_jobs')->insert($lot);
    }
    $cree = etatComptesUnJeton(['files']);

    expect(etatComptesAppeler($cree['entete'])->json('donnees.files.sur_7_jours'))->toBe(['total' => 5_000, 'plafonne' => true]);
});

it('lit chaque compte sous la borne de temps, sans écrire en base hors la trace d’usage du jeton', function (): void {
    etatComptesPlanter([1]);
    $cree = etatComptesUnJeton(['sante', 'exceptions', 'files', 'erreurs-navigateur']);
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    etatComptesAppeler($cree['entete'])->assertOk();

    $ecritures = array_values(array_filter($requetes, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate|lock)\b/i', $sql) === 1));
    $comptes = array_values(array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'select /*+ MAX_EXECUTION_TIME(') && str_contains($sql, '`id` from') && str_contains($sql, '>= ?') && str_contains($sql, 'limit 5001') && ! str_contains($sql, 'order by')));

    expect($ecritures)->toHaveCount(1)
        ->and($comptes)->toHaveCount(3);
});

it('rend 503 lecture-trop-longue quand la base coupe un compte à la borne de temps', function (): void {
    $cree = etatComptesUnJeton(['files']);
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'limit 5001') && str_contains($sql, 'failed_jobs')) {
            $erreur = new PDOException('Query execution was interrupted, maximum statement execution time exceeded');
            $erreur->errorInfo = ['HY000', LecturesBornees::ERREUR_DE_TEMPS_ECOULE, 'Query execution was interrupted'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    etatComptesAppeler($cree['entete'])->assertStatus(503)
        ->assertExactJson(['erreur' => 'lecture-trop-longue', 'note' => EnveloppeDeDiagnostic::NOTE]);
});
