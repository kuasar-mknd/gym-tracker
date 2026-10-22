<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Les clés d'idempotence des séries et des lignes passent en `ascii` /
 * `ascii_bin`, comme celle des séances (#2097).
 *
 * Elles étaient en `utf8mb4_unicode_ci`, la collation des tables : deux clés
 * qui ne diffèrent que par la casse y sont une seule clé. `CreateSetAction` et
 * `CreateWorkoutLineAction` rendaient alors à la seconde tentative la série ou
 * la ligne de la première, et l'index unique refusait de les tenir côte à
 * côte. Une égalité d'identité ne se confie pas à une collation insensible à
 * la casse (`.ai/rules/actions.md`).
 *
 * Avant de changer la collation, chaque clé que
 * `CreateSetAction::idempotencyKey()` refuserait (hors de l'ASCII imprimable,
 * espace comprise, vide) est effacée, sa ligne gardée. Effacer, et non refuser
 * la migration : aucune recherche ne trouvera plus jamais une telle clé, puisque
 * chaque clé reçue passe par ce motif et que les clés dérivées par le serveur
 * (`modele-ligne:…`, `modele-serie:…`) n'en sortent pas ; sa ligne n'y perd
 * rien. La garder arrêterait la migration au milieu d'un déploiement : une clé
 * hors ASCII ne se convertit pas (1366, « Incorrect string value »). Et une
 * clé ASCII à espace finale, écrite avant le motif (#2071), se convertit, mais
 * rendrait encore sa série à la clé sans l'espace : `ascii_bin`, comme
 * `utf8mb4_unicode_ci` et toute collation de MySQL hors des `utf8mb4_0900_*`,
 * tient une espace finale pour insignifiante (PAD SPACE). C'est le motif de la
 * clé, sans espace, qui écarte ce cas, pas la colonne.
 *
 * Le motif se juge dans la base, en une instruction par table : le même que
 * celui de `idempotencyKey()` (`/^[\x21-\x7E]{1,64}$/D`), écrit pour ICU.
 * Sensible à la casse (`'c'`) : sous la collation de la colonne, ICU étend la
 * classe à ses variantes de casse, et le signe kelvin ou le s long y
 * entreraient. Terminé par `\z` : `$` admet un saut de ligne final.
 * `ClesDIdempotenceEnAsciiTest` compare chaque verdict à celui de PHP.
 *
 * La colonne garde sa place en tête de son index unique, `(idempotency_key,
 * workout_line_id)` et `(idempotency_key, workout_id)` (#2095) : `MODIFY`
 * reconstruit la table avec les mêmes index, et chaque clé étrangère reste
 * portée par un index sans colonne nulle (`ChaqueCleEtrangereRefuseUneLigneOrphelineTest`,
 * en CI sur 8.4 et 9.7.2). Une ligne orpheline (une archive restaurée, une
 * écriture faite hors d'Eloquent) n'arrête rien : l'effacement ne réécrit que
 * l'index unique, qui ne porte aucune clé étrangère, et la reconstruction se
 * fait clés coupées (`changer()`).
 *
 * Chaque table se passe seule, et seulement si sa colonne n'est pas déjà en
 * `ascii_bin` : une reprise après un échec retombe sur ses pieds
 * (`MigrationsRejouablesTest`).
 *
 * `down()` rend la collation des tables, et s'arrête (1062) sur deux clés d'un
 * même parent que seule la casse distingue : l'ancien index unique les
 * confond, et le retour en arrière ne choisit pas laquelle oublier.
 */
return new class() extends Migration
{
    /**
     * Les tables dont la clé d'idempotence passe en `ascii_bin`.
     *
     * @var list<string>
     */
    private const array TABLES = ['sets', 'workout_lines'];

    /**
     * Le motif de `CreateSetAction::idempotencyKey()`, écrit pour les
     * expressions régulières de MySQL (ICU) : de 1 à 64 caractères de U+0021 à
     * U+007E, jusqu'à la fin exacte de la valeur.
     */
    private const string CLE_ADMISE = '^[\x{21}-\x{7E}]{1,64}\z';

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'idempotency_key') || $this->collation($table) === 'ascii_bin') {
                continue;
            }

            DB::table($table)
                ->whereNotNull('idempotency_key')
                ->whereRaw("not regexp_like(`idempotency_key`, ?, 'c')", [self::CLE_ADMISE])
                ->update(['idempotency_key' => null]);

            $this->changer($table, 'ascii', 'ascii_bin');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'idempotency_key') || $this->collation($table) !== 'ascii_bin') {
                continue;
            }

            $this->changer($table, 'utf8mb4', 'utf8mb4_unicode_ci');
        }
    }

    /**
     * Change le jeu de caractères et la collation de la clé, le reste de la
     * colonne tel qu'il était (64 caractères, nullable).
     *
     * Les clés étrangères sont coupées le temps de l'ordre, qui ne touche
     * aucune de leurs colonnes : un changement de jeu de caractères recopie
     * la table (`ALGORITHM=COPY`), et 9.7.2 revérifie chaque clé étrangère
     * des lignes recopiées, là où 8.4 ne le fait pas (mesuré sur les deux
     * serveurs). Une ligne orpheline arrêterait la migration au milieu
     * (1452) ; elle reste ce qu'elle était, et la vérification d'avant la
     * bascule la compte (`docker/mysql/avant-mysql-9-7.sql`). La clé reste
     * vérifiée pour chaque écriture qui suit.
     */
    private function changer(string $table, string $jeu, string $collation): void
    {
        Schema::withoutForeignKeyConstraints(static function () use ($table, $jeu, $collation): void {
            Schema::table($table, static function (Blueprint $blueprint) use ($jeu, $collation): void {
                $blueprint->string('idempotency_key', 64)->charset($jeu)->collation($collation)->nullable()->change();
            });
        });
    }

    /**
     * La collation de la clé d'idempotence de la table, telle que la base la
     * tient.
     */
    private function collation(string $table): ?string
    {
        foreach (Schema::getColumns($table) as $description) {
            if ($description['name'] === 'idempotency_key') {
                return is_string($description['collation']) ? $description['collation'] : null;
            }
        }

        return null;
    }
};
