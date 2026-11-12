<?php

declare(strict_types=1);

use App\Models\Set;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `sets.is_pr` ne se lit ni ne s'écrit nulle part (#1513).
 *
 * Aucune ligne du dépôt ne l'a jamais lue ni écrite : ni l'application, ni le
 * client, ni le panneau, ni les fabriques, ni les tests. Un record se lit dans
 * `personal_records`, qui désigne sa série par `set_id`. La colonne venait du
 * schéma figé (`database/schema/mysql-schema.sql`), qu'une base neuve charge
 * avant les migrations, et `2026_08_18_141047_drop_is_pr_from_sets_table.php`
 * la retire, à garde, de chaque base qui la porte ; une base migrée pas à pas
 * ne l'a jamais eue. Le schéma figé la créait encore, pour qu'une migration la
 * retire aussitôt : il ne la déclare plus, et l'audit du modèle, qui lisait le
 * schéma figé, la croyait toujours là.
 *
 * Aucune migration ne s'ajoute : celle du 2026-08-18 fait déjà ce qu'il faut,
 * et `down()` la recrée. Ses deux ordres se passent sans recopier la table,
 * sur MySQL 8.4 comme sur 9.7, où une recopie revérifierait chaque clé
 * étrangère (`.ai/rules/database.md`).
 *
 * Ces cas passent par des ordres DDL, qui valident la transaction de
 * `RefreshDatabase` : ce fichier fait lui-même ce que le `migrate:fresh` qui
 * suivrait referait (`.ai/rules/tests.md`).
 */

/**
 * La migration qui retire la colonne, chargée depuis son fichier.
 */
function colonneIsPrMigration(): Migration
{
    $migration = require database_path('migrations/2026_08_18_141047_drop_is_pr_from_sets_table.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function colonneIsPrMonter(): void
{
    $migration = colonneIsPrMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Joue `down()` de la migration, comme `migrate:rollback`.
 */
function colonneIsPrDescendre(): void
{
    $migration = colonneIsPrMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * Les ordres `alter table` envoyés pendant l'appel.
 *
 * @return list<string>
 */
function colonneIsPrOrdresPendant(callable $appel): array
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
 * La colonne qui précède `is_pr` dans la table, ou null sans elle.
 */
function colonneIsPrPrecedente(): ?string
{
    $precedente = null;

    foreach (Schema::getColumns('sets') as $colonne) {
        if ($colonne['name'] === 'is_pr') {
            return $precedente;
        }

        $precedente = $colonne['name'];
    }

    return null;
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

    colonneIsPrMonter();

    DB::getPdo()->beginTransaction();
});

it('ne crée plus la colonne dans le schéma figé', function (): void {
    $schema = (string) file_get_contents(database_path('schema/mysql-schema.sql'));

    expect(preg_match('/CREATE TABLE `sets` \((.*?)\n\) ENGINE/s', $schema, $table))->toBe(1)
        ->and($table[1] ?? '')->toContain('`is_completed`')
        ->and($table[1] ?? '')->not->toContain('`is_pr`');
});

it('ne la laisse dans aucune base migrée', function (): void {
    expect(Schema::hasColumn('sets', 'is_pr'))->toBeFalse();
});

it('la recrée à down(), fausse et derrière is_completed, puis la retire à up() sans toucher aux séries, et ne fait plus rien ensuite', function (): void {
    $serie = Set::factory()->create();

    colonneIsPrDescendre();

    expect(colonneIsPrPrecedente())->toBe('is_completed')
        ->and(DB::table('sets')->where('id', $serie->id)->value('is_pr'))->toBe(0);

    $retrait = colonneIsPrOrdresPendant(colonneIsPrMonter(...));
    $seconde = colonneIsPrOrdresPendant(colonneIsPrMonter(...));

    expect(Schema::hasColumn('sets', 'is_pr'))->toBeFalse()
        ->and($retrait)->toBe(['alter table `sets` drop `is_pr`'])
        ->and($seconde)->toBe([])
        ->and(DB::table('sets')->where('id', $serie->id)->exists())->toBeTrue();
});

/*
 * MySQL choisit de lui-même l'algorithme le moins coûteux ; réclamé, `INSTANT`
 * fait échouer l'ordre que le serveur ne saurait passer qu'en recopiant la
 * table. Les ordres sont ceux que la migration envoie : le retrait relevé
 * pendant qu'il passe, l'ajout à blanc.
 */
it('retire et recrée la colonne sans recopier la table', function (): void {
    Set::factory()->create();

    colonneIsPrDescendre();
    $retrait = colonneIsPrOrdresPendant(colonneIsPrMonter(...));
    colonneIsPrDescendre();

    $ajout = array_values(array_filter(
        array_column(DB::pretend(colonneIsPrDescendre(...)), 'query'),
        static fn (string $ordre): bool => str_starts_with($ordre, 'alter table'),
    ));

    expect($retrait)->toHaveCount(1)
        ->and($ajout)->toHaveCount(1);

    DB::statement($retrait[0].', algorithm=instant');

    expect(Schema::hasColumn('sets', 'is_pr'))->toBeFalse();

    DB::statement($ajout[0].', algorithm=instant');

    expect(colonneIsPrPrecedente())->toBe('is_completed');
});
