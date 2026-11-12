<?php

declare(strict_types=1);

use App\Models\JetonDeDiagnostic;
use App\Models\TachePlanifiee;
use App\Models\User;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EtatDuDiagnostic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

/*
 * Ce que rend la route d'état du diagnostic (#2032) : l'application, le
 * jeton, et pour chaque source que ses portées ouvrent, quelques groupes
 * seulement, dont le dépassement se dit.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
});

/**
 * @param  list<string>  $portees
 */
function etatDuDiagnosticJeton(array $portees): JetonDeDiagnostic
{
    return JetonDeDiagnostic::factory()->make(['identifiant' => 'abcdefgh1234', 'portees' => $portees, 'expire_le' => now()->addDays(7)]);
}

/**
 * @param  list<string>  $portees
 * @return array<string, array<string, mixed>>
 */
function etatDuDiagnosticDonnees(array $portees): array
{
    /** @var array<string, array<string, mixed>> $donnees */
    $donnees = (new EtatDuDiagnostic())(etatDuDiagnosticJeton($portees), now())->enveloppe['donnees'];

    return $donnees;
}

function etatDuDiagnosticDesExceptions(int $groupes): void
{
    foreach (range(1, $groupes) as $rang) {
        DB::table('filament_exceptions_table')->insert([
            'type' => 'RuntimeException', 'code' => '0', 'message' => 'texte venu de l’extérieur', 'file' => base_path('app/Models/User.php'),
            'line' => $rang, 'trace' => '[]', 'method' => 'GET', 'path' => '/dashboard', 'created_at' => now()->subMinutes($rang), 'updated_at' => now(),
        ]);
    }
}

function etatDuDiagnosticDesErreursNavigateur(int $groupes): void
{
    $compte = User::factory()->create();

    foreach (range(1, $groupes) as $rang) {
        DB::table('erreurs_navigateur')->insert([
            'user_id' => $compte->id, 'type' => 'error', 'message' => 'texte venu de l’extérieur', 'url' => 'https://example.org/page', 'agent' => 'Mozilla',
            'empreinte' => md5((string) $rang), 'created_at' => now()->subMinutes($rang), 'updated_at' => now(),
        ]);
    }
}

it('rend l’application et le jeton, rien d’une source sans sa portée', function (): void {
    $enveloppe = new EtatDuDiagnostic()(etatDuDiagnosticJeton(['files']), now());
    /** @var array<string, array<string, mixed>> $donnees */
    $donnees = $enveloppe->enveloppe['donnees'];

    expect($donnees)->toHaveKeys(['application', 'jeton', 'files'])
        ->and(array_intersect(array_keys($donnees), ['versions', 'sante', 'taches_planifiees', 'exceptions', 'erreurs_navigateur']))->toBe([])
        ->and($donnees['jeton'])->toBe(['fin' => '1234', 'portees' => ['files'], 'expire_le' => '2026-11-19T10:00:00+01:00'])
        ->and($enveloppe->enveloppe['note'])->toBe(EnveloppeDeDiagnostic::NOTE)
        ->and($enveloppe->enveloppe['suivant'])->toBeNull()
        ->and($enveloppe->enveloppe['genere_le'])->toBe('2026-11-12T10:00:00+01:00');
});

it('ignore une portée que l’énumération ne connaît pas, et ne tient pas le texte brut pour une source', function (): void {
    $enveloppe = new EtatDuDiagnostic()(etatDuDiagnosticJeton(['texte-brut', 'inconnue']), now());
    /** @var array<string, array<string, mixed>> $donnees */
    $donnees = $enveloppe->enveloppe['donnees'];

    expect($donnees)->toHaveKeys(['application', 'jeton'])->toHaveCount(2)
        ->and($donnees['jeton']['portees'])->toBe(['texte-brut']);
});

it('ne garde que cinq groupes d’exceptions, et dit qu’il en reste', function (int $groupes, int $montres, bool $tronque): void {
    etatDuDiagnosticDesExceptions($groupes);

    $enveloppe = new EtatDuDiagnostic()(etatDuDiagnosticJeton(['exceptions']), now());
    /** @var array<string, array<string, mixed>> $donnees */
    $donnees = $enveloppe->enveloppe['donnees'];

    expect($donnees['exceptions']['groupes'])->toHaveCount($montres)
        ->and($donnees['exceptions']['tronque'])->toBe($tronque)
        ->and($enveloppe->enveloppe['tronque'])->toBe($tronque);
})->with([[4, 4, false], [5, 5, false], [6, 5, true]]);

it('ne garde que trois groupes d’erreurs du navigateur, et dit qu’il en reste', function (int $groupes, int $montres, bool $tronque): void {
    etatDuDiagnosticDesErreursNavigateur($groupes);

    $enveloppe = new EtatDuDiagnostic()(etatDuDiagnosticJeton(['erreurs-navigateur']), now());
    /** @var array<string, array<string, mixed>> $donnees */
    $donnees = $enveloppe->enveloppe['donnees'];

    expect($donnees['erreurs_navigateur']['groupes'])->toHaveCount($montres)
        ->and($donnees['erreurs_navigateur']['tronque'])->toBe($tronque);
})->with([[2, 2, false], [3, 3, false], [4, 3, true]]);

it('rend l’état d’Horizon et les échecs de files sans en garder plus de cinq groupes', function (): void {
    $enveloppe = new EtatDuDiagnostic()(etatDuDiagnosticJeton(['files']), now());
    /** @var array<string, array<string, mixed>> $donnees */
    $donnees = $enveloppe->enveloppe['donnees'];

    expect($donnees['files'])->toHaveKeys(['horizon', 'echecs', 'lignes_lues', 'echantillon', 'tronque'])
        ->and($donnees['files']['echecs'])->toBe([])
        ->and($donnees['files']['tronque'])->toBeFalse();
});

it('ne rend de la santé que le passage et les contrôles qui ne sont pas au vert', function (): void {
    Cache::forever('health:storeResults', new StoredCheckResults(Carbon::parse('2026-11-12 09:55:00')->toDateTime(), collect([
        new StoredCheckResult('Database', 'Base de données', '', 'ok', 'ok'),
        new StoredCheckResult('Redis', 'Redis', 'Refusé', 'refusé', 'failed', ['hote' => 'redis.example.org']),
        new StoredCheckResult('Cache', 'Cache', 'Lent', 'lent', 'warning'),
    ]))->toJson());

    $sante = etatDuDiagnosticDonnees(['sante'])['sante'];

    expect($sante)->toBe([
        'dernier_passage_le' => '2026-11-12T09:55:00+01:00',
        'age_en_secondes' => 300,
        'controles_hors_du_vert' => [
            ['nom' => 'Cache', 'libelle' => 'Cache', 'statut' => 'warning'],
            ['nom' => 'Redis', 'libelle' => 'Redis', 'statut' => 'failed'],
        ],
        'tronque' => false,
    ]);
});

it('ne garde que dix contrôles hors du vert, et dit qu’il en reste', function (int $controles, int $montres, bool $tronque): void {
    Cache::forever('health:storeResults', new StoredCheckResults(Carbon::parse('2026-11-12 09:55:00')->toDateTime(), collect(array_map(
        static fn (int $rang): StoredCheckResult => new StoredCheckResult('Controle'.$rang, 'Contrôle '.$rang, '', 'lent', 'warning'),
        range(1, $controles),
    )))->toJson());

    $sante = etatDuDiagnosticDonnees(['sante'])['sante'];

    expect($sante['controles_hors_du_vert'])->toHaveCount($montres)
        ->and($sante['tronque'])->toBe($tronque);
})->with([[9, 9, false], [10, 10, false], [11, 10, true]]);

it('ne rend des tâches que celles qui sont en retard ou échouées, sans le détail de leurs échecs', function (): void {
    TachePlanifiee::query()->create(['name' => 'a:ordinaire', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5, 'last_started_at' => now()->subMinute(), 'last_finished_at' => now()]);
    TachePlanifiee::query()->create(['name' => 'b:echouee', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5, 'last_failed_at' => now()]);

    $taches = etatDuDiagnosticDonnees(['sante'])['taches_planifiees'];

    expect($taches['taches'])->toBe([
        ['commande' => 'b:echouee', 'etat' => 'echouee', 'dernier_echec_le' => '2026-11-12T10:00:00+01:00', 'echecs_sur_7_jours' => 0],
    ])->and($taches['tronque'])->toBeFalse()
        ->and($taches['echantillon'])->toBeFalse();
});

it('ne garde que dix tâches en difficulté, et dit qu’il en reste', function (int $taches, int $montrees, bool $tronque): void {
    foreach (range(1, $taches) as $rang) {
        TachePlanifiee::query()->create(['name' => 'tache:'.$rang, 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5, 'last_failed_at' => now()]);
    }

    $liste = etatDuDiagnosticDonnees(['sante'])['taches_planifiees'];

    expect($liste['taches'])->toHaveCount($montrees)
        ->and($liste['tronque'])->toBe($tronque);
})->with([[9, 9, false], [10, 10, false], [11, 10, true]]);

it('reste sous 8 Kio, toutes portées accordées et chaque source pleine', function (): void {
    etatDuDiagnosticDesExceptions(60);
    etatDuDiagnosticDesErreursNavigateur(60);

    $json = new EtatDuDiagnostic()(etatDuDiagnosticJeton(['sante', 'exceptions', 'files', 'erreurs-navigateur', 'texte-brut']), now())->json();

    expect(strlen($json))->toBeLessThan(8 * 1024)
        ->and($json)->not->toContain('texte venu de l’extérieur')
        ->and($json)->not->toContain('"non_fiable":');
});
