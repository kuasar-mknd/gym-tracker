<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La migration qui rattache les exceptions enregistrées au compte (#1938)
 * ajoute `filament_exceptions_table.user_id`, puis sa clé en
 * `ON DELETE CASCADE`, en deux ordres séparés ; `down()` les retire dans
 * l'ordre inverse. Une reprise après un échec entre les deux, ou un second
 * passage, doit retomber sur ses pieds, comme pour la migration des erreurs
 * du navigateur (`ErreursNavigateurSuiventLeCompteTest`) : `MigrationsRejouablesTest`
 * ne lit que le texte de `up()`, et ne voit ni `dropForeign` ni la méthode
 * qui interroge la clé. La migration est donc jouée pour de vrai, sur MySQL.
 * L'état interrompu est le même dans les deux sens : la colonne sans sa clé.
 *
 * Chaque ordre DDL valide la transaction de `RefreshDatabase` : ce fichier
 * fait lui-même ce que le `migrate:fresh` qui suivrait referait
 * (`.ai/rules/tests.md`).
 */

/**
 * La migration, chargée depuis son fichier.
 */
function exceptionsLieesMigration(): Migration
{
    $migration = require database_path('migrations/2026_10_12_041918_rattacher_les_exceptions_au_compte.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function exceptionsLieesMonter(): void
{
    $migration = exceptionsLieesMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Joue `down()` de la migration, comme `migrate:rollback`.
 */
function exceptionsLieesDescendre(): void
{
    $migration = exceptionsLieesMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * La règle de suppression de la clé de `user_id`, nulle quand la clé manque.
 */
function exceptionsLieesRegleDeLaCle(): ?string
{
    $cle = collect(Schema::getForeignKeys('filament_exceptions_table'))->firstWhere('name', 'filament_exceptions_table_user_id_foreign');

    return is_array($cle) && is_string($cle['on_delete'] ?? null) ? $cle['on_delete'] : null;
}

/**
 * Les ordres qui modifient une table, lancés pendant cette étape.
 *
 * @return list<string>
 */
function exceptionsLieesOrdresDe(Closure $etape): array
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

    exceptionsLieesMonter();

    DB::getPdo()->beginTransaction();
});

it('ne touche à rien quand la colonne et sa clé sont déjà là', function (): void {
    expect(exceptionsLieesRegleDeLaCle())->toBe('cascade')
        ->and(exceptionsLieesOrdresDe(exceptionsLieesMonter(...)))->toBe([])
        ->and(exceptionsLieesRegleDeLaCle())->toBe('cascade');
});

it('reprend un passage interrompu entre la colonne et sa clé', function (): void {
    DB::statement('alter table filament_exceptions_table drop foreign key filament_exceptions_table_user_id_foreign');

    expect(exceptionsLieesRegleDeLaCle())->toBeNull()
        ->and(Schema::hasColumn('filament_exceptions_table', 'user_id'))->toBeTrue();

    exceptionsLieesMonter();

    expect(exceptionsLieesRegleDeLaCle())->toBe('cascade')
        ->and(Schema::hasColumn('filament_exceptions_table', 'user_id'))->toBeTrue();
});

it('reprend un retour en arrière interrompu entre la clé et la colonne', function (): void {
    DB::statement('alter table filament_exceptions_table drop foreign key filament_exceptions_table_user_id_foreign');

    exceptionsLieesDescendre();

    expect(Schema::hasColumn('filament_exceptions_table', 'user_id'))->toBeFalse()
        ->and(exceptionsLieesRegleDeLaCle())->toBeNull();
});

it('se défait et se rejoue, deux fois de suite', function (): void {
    exceptionsLieesDescendre();

    expect(Schema::hasColumn('filament_exceptions_table', 'user_id'))->toBeFalse()
        ->and(exceptionsLieesRegleDeLaCle())->toBeNull()
        ->and(exceptionsLieesOrdresDe(exceptionsLieesDescendre(...)))->toBe([]);

    exceptionsLieesMonter();

    expect(Schema::hasColumn('filament_exceptions_table', 'user_id'))->toBeTrue()
        ->and(exceptionsLieesRegleDeLaCle())->toBe('cascade')
        ->and(exceptionsLieesOrdresDe(exceptionsLieesMonter(...)))->toBe([])
        ->and(exceptionsLieesRegleDeLaCle())->toBe('cascade');
});
