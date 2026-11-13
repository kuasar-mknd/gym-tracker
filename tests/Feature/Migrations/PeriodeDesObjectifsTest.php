<?php

declare(strict_types=1);

use App\Models\Goal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `goals.period` (« total » ou « week ») : une chaîne obligatoire à valeur par
 * défaut, ajoutée sans recopier la table, que `up()` ne rejoue pas et que
 * `down()` retire.
 *
 * Ces cas passent par des ordres DDL, qui valident la transaction de
 * `RefreshDatabase` : ce fichier remet lui-même la colonne en place ensuite
 * (`.ai/rules/tests.md`).
 */

/**
 * La migration, chargée depuis son fichier.
 */
function periodeDesObjectifsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_13_080000_ajouter_la_periode_aux_objectifs.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` ou `down()` de la migration, comme `migrate` ou `migrate:rollback`.
 */
function periodeDesObjectifsJouer(string $sens): void
{
    $migration = periodeDesObjectifsMigration();
    assert(method_exists($migration, $sens));

    $migration->{$sens}();
}

/**
 * Les ordres `alter table` envoyés pendant l'appel.
 *
 * @return list<string>
 */
function periodeDesObjectifsOrdresPendant(callable $appel): array
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

    periodeDesObjectifsJouer('up');

    DB::getPdo()->beginTransaction();
});

it('donne à chaque objectif existant la période « total »', function (): void {
    $objectif = Goal::factory()->create(['type' => 'frequency']);

    expect(Schema::hasColumn('goals', 'period'))->toBeTrue()
        ->and(DB::table('goals')->where('id', $objectif->id)->value('period'))->toBe('total');
});

it('retire la colonne à down(), la recrée à up() avec son défaut, et ne fait plus rien ensuite', function (): void {
    $objectif = Goal::factory()->create();

    $retrait = periodeDesObjectifsOrdresPendant(fn () => periodeDesObjectifsJouer('down'));
    $secondRetrait = periodeDesObjectifsOrdresPendant(fn () => periodeDesObjectifsJouer('down'));

    expect(Schema::hasColumn('goals', 'period'))->toBeFalse()
        ->and($retrait)->toHaveCount(1)
        ->and($secondRetrait)->toBe([]);

    $ajout = periodeDesObjectifsOrdresPendant(fn () => periodeDesObjectifsJouer('up'));
    $secondAjout = periodeDesObjectifsOrdresPendant(fn () => periodeDesObjectifsJouer('up'));

    expect(Schema::hasColumn('goals', 'period'))->toBeTrue()
        ->and($ajout)->toHaveCount(1)
        ->and($secondAjout)->toBe([])
        ->and(DB::table('goals')->where('id', $objectif->id)->value('period'))->toBe('total');
});

it('ajoute et retire la colonne sans recopier la table', function (): void {
    $ordres = periodeDesObjectifsOrdresPendant(fn () => periodeDesObjectifsJouer('down'));
    periodeDesObjectifsJouer('up');

    DB::statement($ordres[0].', algorithm=instant');
    expect(Schema::hasColumn('goals', 'period'))->toBeFalse();

    $ajout = array_values(array_filter(
        array_column(DB::pretend(fn () => periodeDesObjectifsJouer('up')), 'query'),
        static fn (string $ordre): bool => str_starts_with($ordre, 'alter table'),
    ));

    expect($ajout)->toHaveCount(1);

    DB::statement($ajout[0].', algorithm=instant');
    expect(Schema::hasColumn('goals', 'period'))->toBeTrue();
});
