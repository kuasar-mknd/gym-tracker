<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Storage\DatabaseStorage;
use Laravel\Pulse\Value;

/*
 * Pulse fait calculer `key_hash` par le serveur, et MySQL 9.7 ne fournit plus
 * `md5()` en fonction native : le schéma figé ne s'y chargeait plus, et une
 * base 8.4 qui garde l'ancienne expression ne passe pas la mise à niveau
 * (`database/migrations/2026_10_17_145842_hacher_les_cles_de_pulse_sans_md5.php`).
 * Ces tests tournent sur le serveur de la suite : 8.4 et 9.7.2 en CI.
 *
 * Deux d'entre eux passent par un ordre DDL, qui valide la transaction de
 * `RefreshDatabase` : ce fichier fait lui-même ce que le `migrate:fresh` qui
 * suivrait referait (`.ai/rules/tests.md`).
 */

/**
 * La migration, chargée depuis son fichier.
 */
function empreintePulseMigration(): Migration
{
    $migration = require database_path('migrations/2026_10_17_145842_hacher_les_cles_de_pulse_sans_md5.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function empreintePulseMonter(): void
{
    $migration = empreintePulseMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

/**
 * L'expression de `key_hash` de chaque table de Pulse, telle que le serveur
 * la rend.
 *
 * @return array<string, string|null>
 */
function empreintePulseExpressions(): array
{
    $expressions = [];

    foreach (['pulse_values', 'pulse_entries', 'pulse_aggregates'] as $table) {
        $colonne = collect(Schema::getColumns($table))->firstWhere('name', 'key_hash');
        $expression = is_array($colonne) ? data_get($colonne, 'generation.expression') : null;
        $expressions[$table] = is_string($expression) ? $expression : null;
    }

    return $expressions;
}

/**
 * L'empreinte attendue d'une clé : les seize premiers octets de son SHA-256.
 */
function empreintePulseDe(string $cle): string
{
    return substr(hash('sha256', $cle, true), 0, 16);
}

/**
 * Écrit ces mesures par le stockage de Pulse, en un seul passage.
 */
function empreintePulseStocker(Entry|Value ...$mesures): void
{
    /** @var Collection<int, Entry|Value> $lot */
    $lot = collect(array_values($mesures));

    app(DatabaseStorage::class)->store($lot);
}

/**
 * Vrai quand le serveur fournit encore `md5()` en fonction native, comme
 * MySQL 8.4 : sous 9.7, l'appel cherche une fonction stockée du schéma.
 */
function empreintePulseLeServeurCalculeMd5(): bool
{
    try {
        DB::select("select md5('a') as empreinte");

        return true;
    } catch (QueryException) {
        return false;
    }
}

/**
 * Les ordres qui modifient une table, lancés pendant cette étape.
 *
 * @return list<string>
 */
function empreintePulseOrdresDe(Closure $etape): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $etape();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_map(static fn (array $requete): string => (string) $requete['query'], DB::getQueryLog()),
        static fn (string $requete): bool => str_starts_with(mb_strtolower($requete), 'alter table'),
    ));
}

afterEach(function (): void {
    if (DB::getPdo()->inTransaction()) {
        return;
    }

    Schema::withoutForeignKeyConstraints(function (): void {
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->delete();
            }
        }
    });

    empreintePulseMonter();

    DB::getPdo()->beginTransaction();
});

it('calcule l’empreinte de chaque table de Pulse par SHA-256, sans md5', function (): void {
    expect(empreintePulseExpressions())->toBe([
        'pulse_values' => 'unhex(left(sha2(`key`,256),32))',
        'pulse_entries' => 'unhex(left(sha2(`key`,256),32))',
        'pulse_aggregates' => 'unhex(left(sha2(`key`,256),32))',
    ]);
});

/*
 * Le stockage de Pulse n'écrit pas `key_hash` sous MySQL : c'est l'unicité sur
 * l'empreinte calculée par le serveur qui fusionne deux écritures de la même
 * clé. Une empreinte nulle ou changeante laisserait passer les doublons.
 */
it('fusionne deux écritures de la même clé de Pulse', function (): void {
    // Pulse n'agrège pas une entrée plus vieille que la période : l'horloge
    // s'arrête sur l'instant des entrées.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:30', 'UTC'));
    $instant = CarbonImmutable::now()->getTimestamp();

    empreintePulseStocker(new Value($instant, 'system', 'serveur-1', '{"cpu":1}'));
    empreintePulseStocker(new Value($instant + 5, 'system', 'serveur-1', '{"cpu":2}'));
    empreintePulseStocker(
        new Entry($instant, 'slow_request', 'GET /seances', 900)->count(),
        new Entry($instant, 'slow_request', 'GET /seances', 1200)->count(),
    );
    empreintePulseStocker(new Entry($instant + 1, 'slow_request', 'GET /seances', 1500)->count());

    $valeurs = DB::table('pulse_values')->get(['key', 'value', 'key_hash']);
    $agregats = DB::table('pulse_aggregates')->where('period', 60)->get(['key', 'value', 'key_hash']);
    $compte = $agregats->first()?->value;

    expect($valeurs)->toHaveCount(1)
        ->and($valeurs->first()?->value)->toBe('{"cpu":2}')
        ->and($valeurs->first()?->key_hash)->toBe(empreintePulseDe('serveur-1'))
        ->and($agregats)->toHaveCount(1)
        ->and(is_numeric($compte) ? (float) $compte : null)->toBe(3.0)
        ->and($agregats->first()?->key_hash)->toBe(empreintePulseDe('GET /seances'))
        ->and(DB::table('pulse_entries')->where('key_hash', empreintePulseDe('GET /seances'))->count())->toBe(3);
});

it('ne touche à rien quand l’empreinte est déjà SHA-256', function (): void {
    expect(empreintePulseOrdresDe(empreintePulseMonter(...)))->toBe([])
        ->and(array_unique(empreintePulseExpressions()))->toBe(['pulse_values' => 'unhex(left(sha2(`key`,256),32))']);
});

/*
 * L'état d'une base 8.4 d'avant la migration, celui de la production : il ne
 * peut pas exister sous 9.7, qui n'a plus `md5()` en fonction native.
 */
it('remplace l’empreinte md5 d’une base 8.4, lignes existantes comprises', function (): void {
    if (! empreintePulseLeServeurCalculeMd5()) {
        $this->markTestSkipped('Ce serveur ne fournit plus md5() en fonction native : une colonne générée qui l’appelle ne peut pas y exister.');
    }

    foreach (['pulse_values', 'pulse_entries', 'pulse_aggregates'] as $table) {
        DB::statement("alter table `{$table}` modify `key_hash` char(16) character set binary as (unhex(md5(`key`)))");
    }

    DB::table('pulse_values')->insert(['timestamp' => 1, 'type' => 'system', 'key' => 'serveur-1', 'value' => '{}']);

    expect(array_unique(empreintePulseExpressions()))->toBe(['pulse_values' => 'unhex(md5(`key`))'])
        ->and(DB::table('pulse_values')->value('key_hash'))->toBe(md5('serveur-1', true));

    expect(empreintePulseOrdresDe(empreintePulseMonter(...)))->toHaveCount(3)
        ->and(array_unique(empreintePulseExpressions()))->toBe(['pulse_values' => 'unhex(left(sha2(`key`,256),32))'])
        ->and(DB::table('pulse_values')->value('key_hash'))->toBe(empreintePulseDe('serveur-1'));
});
