<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La migration qui sort les colonnes nullables des index portant une clé
 * étrangère (#2095,
 * `database/migrations/2026_10_22_013300_garder_les_cles_etrangeres_hors_des_colonnes_nulles.php`).
 * Ce que le schéma doit devenir, `ChaqueCleEtrangereRefuseUneLigneOrphelineTest`
 * le tient ; ce fichier tient le passage : une base d'avant, des lignes sans
 * date de création, un index qui part alors qu'il était le seul à porter sa
 * clé, un second passage, le retour en arrière.
 *
 * Ces cas passent par des ordres DDL, qui valident la transaction de
 * `RefreshDatabase` : ce fichier fait lui-même ce que le `migrate:fresh` qui
 * suivrait referait (`.ai/rules/tests.md`).
 */

/**
 * La migration, chargée depuis son fichier.
 */
function clesHorsNullesMigration(): Migration
{
    $migration = require database_path('migrations/2026_10_22_013300_garder_les_cles_etrangeres_hors_des_colonnes_nulles.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function clesHorsNullesMonter(): void
{
    $migration = clesHorsNullesMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Joue `down()` de la migration, comme `migrate:rollback` : la base d'avant.
 */
function clesHorsNullesDescendre(): void
{
    $migration = clesHorsNullesMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * Ce que la migration change, forme par forme : chaque index d'avant et
 * d'après, présent ou non, et `created_at` des cinq tables, nullable ou non.
 *
 * @return array<string, bool>
 */
function clesHorsNullesEtat(): array
{
    $formes = [
        'sets unique (workout_line_id, idempotency_key)' => ['sets', ['workout_line_id', 'idempotency_key'], true],
        'sets unique (idempotency_key, workout_line_id)' => ['sets', ['idempotency_key', 'workout_line_id'], true],
        'sets (workout_line_id, weight, reps)' => ['sets', ['workout_line_id', 'weight', 'reps'], false],
        'workout_lines unique (workout_id, idempotency_key)' => ['workout_lines', ['workout_id', 'idempotency_key'], true],
        'workout_lines unique (idempotency_key, workout_id)' => ['workout_lines', ['idempotency_key', 'workout_id'], true],
        'workouts (user_id, ended_at, started_at)' => ['workouts', ['user_id', 'ended_at', 'started_at'], false],
        'workouts (ended_at, user_id, started_at)' => ['workouts', ['ended_at', 'user_id', 'started_at'], false],
        'exercises (user_id, category, name)' => ['exercises', ['user_id', 'category', 'name'], false],
        'exercises (user_id, name)' => ['exercises', ['user_id', 'name'], false],
    ];
    $etat = [];

    foreach ($formes as $nom => [$table, $colonnes, $unique]) {
        $etat[$nom] = Schema::hasIndex($table, $colonnes, $unique ? 'unique' : null);
    }

    foreach (['goals', 'interval_timers', 'macro_calculations', 'wilks_scores', 'workout_templates'] as $table) {
        $colonne = collect(Schema::getColumns($table))->firstWhere('name', 'created_at');
        $etat["{$table}.created_at nullable"] = is_array($colonne) && $colonne['nullable'] === true;
    }

    return $etat;
}

/**
 * L'état attendu : celui d'avant la migration, ou celui d'après.
 *
 * @return array<string, bool>
 */
function clesHorsNullesEtatAttendu(bool $apres): array
{
    return [
        'sets unique (workout_line_id, idempotency_key)' => ! $apres,
        'sets unique (idempotency_key, workout_line_id)' => $apres,
        'sets (workout_line_id, weight, reps)' => ! $apres,
        'workout_lines unique (workout_id, idempotency_key)' => ! $apres,
        'workout_lines unique (idempotency_key, workout_id)' => $apres,
        'workouts (user_id, ended_at, started_at)' => ! $apres,
        'workouts (ended_at, user_id, started_at)' => $apres,
        'exercises (user_id, category, name)' => ! $apres,
        'exercises (user_id, name)' => true,
        'goals.created_at nullable' => ! $apres,
        'interval_timers.created_at nullable' => ! $apres,
        'macro_calculations.created_at nullable' => ! $apres,
        'wilks_scores.created_at nullable' => ! $apres,
        'workout_templates.created_at nullable' => ! $apres,
    ];
}

/**
 * Les ordres qui modifient une table, lancés pendant cette étape.
 *
 * @return list<string>
 */
function clesHorsNullesOrdresDe(Closure $etape): array
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

/**
 * Un compte écrit sans passer par les modèles, pour une ligne qui le vise.
 */
function clesHorsNullesUnCompte(): int
{
    return DB::table('users')->insertGetId(['name' => 'Témoin', 'email' => 'temoin-'.bin2hex(random_bytes(4)).'@example.org']);
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

    clesHorsNullesMonter();

    // Le cas de l'index seul à porter sa clé retire `(user_id, name)` : la
    // table le retrouve, et perd l'index que la migration a posé à sa place.
    if (! Schema::hasIndex('exercises', ['user_id', 'name'])) {
        Schema::table('exercises', function (Blueprint $blueprint): void {
            $blueprint->index(['user_id', 'name']);
        });
    }

    if (Schema::hasIndex('exercises', ['user_id'])) {
        Schema::table('exercises', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['user_id']);
        });
    }

    DB::getPdo()->beginTransaction();
});

it('rend à down() les index et les dates d’avant', function (): void {
    clesHorsNullesDescendre();

    expect(clesHorsNullesEtat())->toBe(clesHorsNullesEtatAttendu(apres: false));
});

it('range chaque index d’une base d’avant, et rend `created_at` obligatoire', function (): void {
    clesHorsNullesDescendre();
    clesHorsNullesMonter();

    expect(clesHorsNullesEtat())->toBe(clesHorsNullesEtatAttendu(apres: true));
});

it('date une ligne qui n’en avait pas avant d’exiger la date de création', function (): void {
    clesHorsNullesDescendre();

    $compte = clesHorsNullesUnCompte();
    $modifiee = DB::table('goals')->insertGetId(['user_id' => $compte, 'title' => 'Modifiée', 'type' => 'weight', 'target_value' => 100, 'created_at' => null, 'updated_at' => '2026-05-01 10:00:00']);
    $jamaisDatee = DB::table('goals')->insertGetId(['user_id' => $compte, 'title' => 'Jamais datée', 'type' => 'weight', 'target_value' => 100, 'created_at' => null, 'updated_at' => null]);
    $datee = DB::table('goals')->insertGetId(['user_id' => $compte, 'title' => 'Datée', 'type' => 'weight', 'target_value' => 100, 'created_at' => '2026-03-01 08:00:00', 'updated_at' => '2026-06-01 08:00:00']);

    clesHorsNullesMonter();

    expect(DB::table('goals')->where('id', $modifiee)->value('created_at'))->toBe('2026-05-01 10:00:00')
        ->and(DB::table('goals')->where('id', $jamaisDatee)->value('created_at'))->toBeString()
        ->and(DB::table('goals')->where('id', $datee)->value('created_at'))->toBe('2026-03-01 08:00:00')
        ->and(DB::table('goals')->count())->toBe(3);
});

it('garde un index à la clé quand celui qui part était le seul à la porter', function (): void {
    clesHorsNullesDescendre();
    DB::statement('alter table `exercises` drop index `exercises_user_id_name_index`');

    clesHorsNullesMonter();

    $compte = clesHorsNullesUnCompte();

    $index = array_column(array_filter(Schema::getIndexes('exercises'), static fn (array $description): bool => ! $description['primary']), 'columns');

    expect($index)->toBe([['user_id']])
        ->and(fn () => DB::table('exercises')->insert(['name' => 'Orphelin', 'category' => null, 'user_id' => $compte + 1000]))->toThrow(QueryException::class, '1452');
});

it('ne change rien à une base déjà rangée', function (): void {
    expect(clesHorsNullesOrdresDe(clesHorsNullesMonter(...)))->toBe([]);
});

it('garde une clé d’idempotence unique sous son parent, et libre d’un parent à l’autre', function (): void {
    $compte = clesHorsNullesUnCompte();
    $exercice = DB::table('exercises')->insertGetId(['name' => 'Développé', 'user_id' => $compte]);
    $seance = DB::table('workouts')->insertGetId(['user_id' => $compte, 'started_at' => '2026-10-21 18:00:00']);
    $ligne = DB::table('workout_lines')->insertGetId(['workout_id' => $seance, 'exercise_id' => $exercice, 'idempotency_key' => 'cle-ligne']);
    $autreLigne = DB::table('workout_lines')->insertGetId(['workout_id' => $seance, 'exercise_id' => $exercice]);
    DB::table('sets')->insert(['workout_line_id' => $ligne, 'idempotency_key' => 'cle-serie']);
    DB::table('sets')->insert(['workout_line_id' => $autreLigne, 'idempotency_key' => 'cle-serie']);

    expect(fn () => DB::table('sets')->insert(['workout_line_id' => $ligne, 'idempotency_key' => 'cle-serie']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('workout_lines')->insert(['workout_id' => $seance, 'exercise_id' => $exercice, 'idempotency_key' => 'cle-ligne']))->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('sets')->where('idempotency_key', 'cle-serie')->count())->toBe(2);
});
