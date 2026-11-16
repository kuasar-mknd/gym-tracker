<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * L'index simple `(user_id, exercise_id, type)` des records doublait l'unique
 * de mêmes colonnes (#2325). Ces cas passent par des ordres DDL, qui
 * valident la transaction de `RefreshDatabase` : ce fichier remet lui-même
 * la base dans l'état migré (`.ai/rules/tests.md`).
 */

function indexDesRecordsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_16_090000_retirer_l_index_doublon_des_records.php');
    assert($migration instanceof Migration);

    return $migration;
}

function indexDesRecordsMonter(): void
{
    $migration = indexDesRecordsMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

function indexDesRecordsDescendre(): void
{
    $migration = indexDesRecordsMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * Les ordres `alter table` envoyés pendant l'appel.
 *
 * @return list<string>
 */
function indexDesRecordsOrdresPendant(callable $appel): array
{
    $ordres = [];

    DB::listen(static function (QueryExecuted $requete) use (&$ordres): void {
        if (str_starts_with($requete->sql, 'alter table')) {
            $ordres[] = $requete->sql;
        }
    });

    $appel();

    return $ordres;
}

/**
 * Le nombre d'uniques et d'index simples des records sur ces trois colonnes.
 *
 * @return array{unique: int, simple: int}
 */
function indexDesRecordsSurLesTroisColonnes(): array
{
    $compte = ['unique' => 0, 'simple' => 0];

    foreach (Schema::getIndexes('personal_records') as $index) {
        if ($index['columns'] === ['user_id', 'exercise_id', 'type'] && ! $index['primary']) {
            $compte[$index['unique'] ? 'unique' : 'simple']++;
        }
    }

    return $compte;
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

    if (indexDesRecordsSurLesTroisColonnes()['unique'] === 0) {
        Schema::table('personal_records', function (Blueprint $blueprint): void {
            $blueprint->unique(['user_id', 'exercise_id', 'type'], 'personal_records_user_exercise_type_unique');
        });
    }

    indexDesRecordsMonter();

    DB::getPdo()->beginTransaction();
});

it('ne crée plus l index simple dans le schéma figé', function (): void {
    $schema = (string) file_get_contents(database_path('schema/mysql-schema.sql'));

    expect($schema)->not->toContain('personal_records_user_id_exercise_id_type_index');
});

it('ne laisse que l unique sur ces colonnes dans une base migrée', function (): void {
    expect(indexDesRecordsSurLesTroisColonnes())->toBe(['unique' => 1, 'simple' => 0]);
});

it('recrée l index simple à down(), le retire à up(), garde l unique, et ne fait plus rien ensuite', function (): void {
    $record = PersonalRecord::factory()->create();

    indexDesRecordsDescendre();
    $apresDescente = indexDesRecordsSurLesTroisColonnes();

    $retrait = indexDesRecordsOrdresPendant(indexDesRecordsMonter(...));
    $seconde = indexDesRecordsOrdresPendant(indexDesRecordsMonter(...));

    expect($apresDescente)->toBe(['unique' => 1, 'simple' => 1])
        ->and(indexDesRecordsSurLesTroisColonnes())->toBe(['unique' => 1, 'simple' => 0])
        ->and($retrait)->toHaveCount(1)
        ->and($seconde)->toBe([])
        ->and(DB::table('personal_records')->where('id', $record->id)->exists())->toBeTrue();
});

it('ne retire pas l index simple quand aucun unique ne le remplace', function (): void {
    indexDesRecordsDescendre();
    Schema::table('personal_records', function (Blueprint $blueprint): void {
        $blueprint->dropUnique('personal_records_user_exercise_type_unique');
    });

    $ordres = indexDesRecordsOrdresPendant(indexDesRecordsMonter(...));

    expect($ordres)->toBe([])
        ->and(indexDesRecordsSurLesTroisColonnes())->toBe(['unique' => 0, 'simple' => 1]);
});

it('garde un seul record par compte, exercice et type une fois l index retiré', function (): void {
    $record = PersonalRecord::factory()->create();

    expect(fn () => PersonalRecord::factory()->create([
        'user_id' => $record->user_id,
        'exercise_id' => $record->exercise_id,
        'type' => $record->type,
    ]))->toThrow(UniqueConstraintViolationException::class);
});
