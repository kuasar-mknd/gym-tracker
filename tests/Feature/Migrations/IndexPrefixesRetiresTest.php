<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Cinq index simples n'étaient que le début d'un autre index (#2350). Ces cas
 * passent par des ordres DDL, qui valident la transaction de
 * `RefreshDatabase` : ce fichier remet lui-même la base dans l'état migré
 * (`.ai/rules/tests.md`).
 */

const INDEX_PREFIXES = [
    'sets_workout_line_id_index' => ['sets', 'workout_line_id'],
    'workout_lines_workout_id_index' => ['workout_lines', 'workout_id'],
    'workout_lines_exercise_id_index' => ['workout_lines', 'exercise_id'],
    'habits_user_id_index' => ['habits', 'user_id'],
    'habit_logs_habit_id_index' => ['habit_logs', 'habit_id'],
];

function indexPrefixesMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_19_120000_retirer_les_index_prefixes_d_un_autre_index.php');
    assert($migration instanceof Migration);

    return $migration;
}

function indexPrefixesMonter(): void
{
    $migration = indexPrefixesMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

function indexPrefixesDescendre(): void
{
    $migration = indexPrefixesMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * Les ordres `alter table` envoyés pendant l'appel.
 *
 * @return list<string>
 */
function indexPrefixesOrdresPendant(callable $appel): array
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
 * Le nom de chaque index simple sur la seule colonne de la clé, par table.
 *
 * @return array<string, bool>
 */
function indexPrefixesPresents(): array
{
    $presents = [];

    foreach (INDEX_PREFIXES as $nom => [$table, $colonne]) {
        $presents[$nom] = false;

        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === [$colonne] && ! $index['primary'] && ! $index['unique']) {
                $presents[$nom] = true;
            }
        }
    }

    return $presents;
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

    if (! Schema::hasIndex('sets', ['workout_line_id', 'order'])) {
        Schema::table('sets', function (Blueprint $blueprint): void {
            $blueprint->index(['workout_line_id', 'order'], 'sets_workout_line_id_order_index');
        });
    }

    indexPrefixesMonter();

    DB::getPdo()->beginTransaction();
});

it('ne crée plus ces index dans le schéma figé', function (): void {
    $schema = (string) file_get_contents(database_path('schema/mysql-schema.sql'));

    foreach (array_keys(INDEX_PREFIXES) as $nom) {
        expect($schema)->not->toContain('`'.$nom.'`');
    }
});

it('ne laisse aucun de ces index dans une base migrée', function (): void {
    expect(array_filter(indexPrefixesPresents()))->toBe([]);
});

it('recrée chaque index à down(), le retire à up(), et ne fait plus rien ensuite', function (): void {
    indexPrefixesDescendre();
    $apresDescente = indexPrefixesPresents();

    $retrait = indexPrefixesOrdresPendant(indexPrefixesMonter(...));
    $seconde = indexPrefixesOrdresPendant(indexPrefixesMonter(...));
    $secondeDescente = indexPrefixesOrdresPendant(function (): void {
        indexPrefixesDescendre();
        indexPrefixesDescendre();
    });

    expect(array_filter($apresDescente))->toHaveCount(5)
        ->and($retrait)->toHaveCount(5)
        ->and($seconde)->toBe([])
        ->and($secondeDescente)->toHaveCount(5)
        ->and(array_filter(indexPrefixesPresents()))->toHaveCount(5);
});

it('garde l index simple quand aucun index plus long ne commence par sa colonne', function (): void {
    indexPrefixesDescendre();
    Schema::table('sets', function (Blueprint $blueprint): void {
        $blueprint->dropIndex('sets_workout_line_id_order_index');
    });

    $ordres = indexPrefixesOrdresPendant(indexPrefixesMonter(...));

    expect(indexPrefixesPresents()['sets_workout_line_id_index'])->toBeTrue()
        ->and($ordres)->toHaveCount(4);
});

it('garde l index simple quand l index plus long a une colonne nullable derrière la clé', function (): void {
    indexPrefixesDescendre();
    Schema::table('sets', function (Blueprint $blueprint): void {
        $blueprint->dropIndex('sets_workout_line_id_order_index');
        $blueprint->index(['workout_line_id', 'weight'], 'sets_workout_line_id_weight_test_index');
    });

    $ordres = indexPrefixesOrdresPendant(indexPrefixesMonter(...));

    Schema::table('sets', function (Blueprint $blueprint): void {
        $blueprint->dropIndex('sets_workout_line_id_weight_test_index');
    });

    expect(indexPrefixesPresents()['sets_workout_line_id_index'])->toBeTrue()
        ->and($ordres)->toHaveCount(4);
});
