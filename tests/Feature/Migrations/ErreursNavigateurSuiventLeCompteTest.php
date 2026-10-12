<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La migration qui fait suivre le compte aux erreurs du navigateur (#1938)
 * retire la clé de `erreurs_navigateur.user_id`, puis la repose en
 * `ON DELETE CASCADE`, en deux ordres séparés. Une reprise après un échec
 * entre les deux, ou un second passage, doit retomber sur ses pieds : deux
 * déploiements de production ont déjà été interrompus par une migration qui ne
 * le faisait pas (31/08). `MigrationsRejouablesTest` ne lit que le texte de
 * `up()`, et ni `dropForeign` ni la méthode qui les porte ici : la migration
 * est donc jouée pour de vrai, sur MySQL, dans chacun de ces états.
 *
 * Chaque ordre DDL valide la transaction de `RefreshDatabase`, qui remigrerait
 * alors toute la base avant le test suivant. Ce fichier fait lui-même ce que
 * ce `migrate:fresh` referait (`.ai/rules/tests.md`, comme
 * `RecordsEnDoubleTest`) : les lignes validées effacées, la clé reposée par
 * la migration, et une transaction rouverte.
 */

/**
 * La migration, chargée depuis son fichier.
 */
function erreursNavigateurMigration(): Migration
{
    $migration = require database_path('migrations/2026_10_11_231911_effacer_les_erreurs_navigateur_avec_le_compte.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * La règle de suppression de la clé de `user_id`, nulle quand la clé manque.
 */
function erreursNavigateurRegleDeLaCle(): ?string
{
    $cle = collect(Schema::getForeignKeys('erreurs_navigateur'))->firstWhere('name', 'erreurs_navigateur_user_id_foreign');

    return is_array($cle) && is_string($cle['on_delete'] ?? null) ? $cle['on_delete'] : null;
}

/**
 * Les ordres qui modifient une table, lancés pendant cette étape.
 *
 * @return list<string>
 */
function erreursNavigateurOrdresDe(Closure $etape): array
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

    erreursNavigateurMigration()->up();

    DB::getPdo()->beginTransaction();
});

it('ne touche à rien quand la clé a déjà sa règle', function (): void {
    expect(erreursNavigateurRegleDeLaCle())->toBe('cascade')
        ->and(erreursNavigateurOrdresDe(static fn () => erreursNavigateurMigration()->up()))->toBe([])
        ->and(erreursNavigateurRegleDeLaCle())->toBe('cascade');
});

it('reprend un passage interrompu entre le retrait de la clé et sa pose', function (): void {
    DB::statement('alter table erreurs_navigateur drop foreign key erreurs_navigateur_user_id_foreign');

    expect(erreursNavigateurRegleDeLaCle())->toBeNull()
        ->and(Schema::hasIndex('erreurs_navigateur', ['user_id']))->toBeTrue();

    erreursNavigateurMigration()->up();

    expect(erreursNavigateurRegleDeLaCle())->toBe('cascade')
        ->and(Schema::hasIndex('erreurs_navigateur', ['user_id']))->toBeTrue();
});

it('se défait et se rejoue, deux fois de suite, en gardant l’index de user_id', function (): void {
    erreursNavigateurMigration()->down();

    expect(erreursNavigateurRegleDeLaCle())->toBe('set null')
        ->and(Schema::hasIndex('erreurs_navigateur', ['user_id']))->toBeTrue()
        ->and(erreursNavigateurOrdresDe(static fn () => erreursNavigateurMigration()->down()))->toBe([])
        ->and(erreursNavigateurRegleDeLaCle())->toBe('set null');

    erreursNavigateurMigration()->up();

    expect(erreursNavigateurRegleDeLaCle())->toBe('cascade')
        ->and(Schema::hasIndex('erreurs_navigateur', ['user_id']))->toBeTrue()
        ->and(erreursNavigateurOrdresDe(static fn () => erreursNavigateurMigration()->up()))->toBe([])
        ->and(erreursNavigateurRegleDeLaCle())->toBe('cascade');
});
