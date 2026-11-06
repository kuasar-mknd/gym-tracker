<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La migration qui ajoute le décalage du début et de la fin d'une séance et
 * d'un jeûne (#2159,
 * `database/migrations/2026_11_05_175129_ajouter_le_decalage_des_instants_des_seances_et_des_jeunes.php`).
 * Elle est additive et réversible : `down()` retire les quatre colonnes, une
 * ligne écrite sans elles se lit comme avant une fois remontée, un passage
 * arrêté entre deux colonnes reprend, et chaque ajout se fait sans recopier la
 * table (`ALGORITHM=INSTANT`), sous MySQL 8.4 comme 9.7.4 en CI (9.7.2
 * jusqu'au 2026-11-06).
 *
 * Ces cas passent par des ordres DDL, qui valident la transaction de
 * `RefreshDatabase` : ce fichier fait lui-même ce que le `migrate:fresh` qui
 * suivrait referait (`.ai/rules/tests.md`).
 */

/**
 * La migration, chargée depuis son fichier.
 */
function decalageDesInstantsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_05_175129_ajouter_le_decalage_des_instants_des_seances_et_des_jeunes.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function decalageDesInstantsMonter(): void
{
    $migration = decalageDesInstantsMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Joue `down()` de la migration, comme `migrate:rollback`.
 */
function decalageDesInstantsDescendre(): void
{
    $migration = decalageDesInstantsMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * Les colonnes de décalage présentes, table par table, avec leur type et leur
 * place derrière l'instant qu'elles accompagnent.
 *
 * @return array<string, string>
 */
function decalageDesInstantsColonnes(): array
{
    $colonnes = [];

    foreach (['workouts', 'fasts'] as $table) {
        $precedente = null;

        foreach (Schema::getColumns($table) as $colonne) {
            if (str_ends_with($colonne['name'], '_offset')) {
                $colonnes["{$table}.{$colonne['name']}"] = sprintf('%s %s après %s', $colonne['type'], $colonne['nullable'] ? 'nulle' : 'obligatoire', $precedente);
            }

            $precedente = $colonne['name'];
        }
    }

    ksort($colonnes);

    return $colonnes;
}

/**
 * Les quatre colonnes que la migration pose.
 *
 * @return array<string, string>
 */
function decalageDesInstantsAttendues(): array
{
    return [
        'fasts.end_time_offset' => 'smallint nulle après end_time',
        'fasts.start_time_offset' => 'smallint nulle après start_time',
        'workouts.ended_at_offset' => 'smallint nulle après ended_at',
        'workouts.started_at_offset' => 'smallint nulle après started_at',
    ];
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

    decalageDesInstantsMonter();

    DB::getPdo()->beginTransaction();
});

it('pose les quatre colonnes, nulles, chacune derrière son instant', function (): void {
    expect(decalageDesInstantsColonnes())->toBe(decalageDesInstantsAttendues());
});

it('les retire à down(), et une ligne écrite sans elles se lit comme avant une fois remontées', function (): void {
    decalageDesInstantsDescendre();

    expect(decalageDesInstantsColonnes())->toBe([]);

    $compte = User::factory()->create()->id;
    $seance = DB::table('workouts')->insertGetId(['user_id' => $compte, 'started_at' => '2026-10-25 02:30:00', 'ended_at' => '2026-10-25 02:50:00']);
    $jeune = DB::table('fasts')->insertGetId(['user_id' => $compte, 'start_time' => '2026-10-25 02:30:00', 'target_duration_minutes' => 960, 'type' => '16:8']);

    decalageDesInstantsMonter();
    decalageDesInstantsMonter();

    expect(decalageDesInstantsColonnes())->toBe(decalageDesInstantsAttendues());
    expect([
        Workout::query()->findOrFail($seance)->started_at->toJSON(),
        Workout::query()->findOrFail($seance)->ended_at?->toJSON(),
        Fast::query()->findOrFail($jeune)->start_time->toJSON(),
    ])->toBe(['2026-10-25T01:30:00.000000Z', '2026-10-25T01:50:00.000000Z', '2026-10-25T01:30:00.000000Z']);
});

it('reprend un passage arrêté après les séances, et un retour en arrière arrêté après elles', function (): void {
    decalageDesInstantsDescendre();

    DB::statement('alter table `workouts` add `started_at_offset` smallint null after `started_at`, add `ended_at_offset` smallint null after `ended_at`');
    decalageDesInstantsMonter();

    expect(decalageDesInstantsColonnes())->toBe(decalageDesInstantsAttendues());

    DB::statement('alter table `workouts` drop `started_at_offset`, drop `ended_at_offset`');
    decalageDesInstantsDescendre();

    expect(decalageDesInstantsColonnes())->toBe([]);
});

/*
 * MySQL choisit de lui-même l'algorithme le moins coûteux ; réclamé, `INSTANT`
 * fait échouer l'ordre que le serveur ne saurait passer qu'en recopiant la
 * table. Les ordres sont ceux que la migration envoie, relevés à blanc.
 */
it('ajoute chaque colonne sans recopier la table', function (): void {
    decalageDesInstantsDescendre();

    $ordres = array_values(array_filter(
        array_column(DB::pretend(function (): void {
            decalageDesInstantsMonter();
        }), 'query'),
        fn (string $ordre): bool => str_starts_with($ordre, 'alter table'),
    ));

    expect($ordres)->toHaveCount(4);

    foreach ($ordres as $ordre) {
        DB::statement($ordre.', algorithm=instant');
    }

    expect(decalageDesInstantsColonnes())->toBe(decalageDesInstantsAttendues());
});
