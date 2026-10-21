<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Aucun index qui commence par la colonne d'une clé étrangère ne garde de
 * colonne nullable derrière elle (#2095).
 *
 * MySQL 9.7.2 ne vérifie plus une clé étrangère quand l'index qui la porte a
 * une colonne nulle dans la ligne écrite : une série s'écrivait sous une ligne
 * de séance qui n'existe pas, une ligne sous une séance, un objectif, un
 * minuteur, un calcul, un score, un modèle ou un exercice sous un compte, que
 * 8.4 refuse (« 1452 … a foreign key constraint fails »). L'index qui porte la
 * clé est le premier de ceux qui commencent par ses colonnes, dans l'ordre que
 * la table tient de son histoire : une base créée par d'autres migrations peut
 * la porter par un autre index que la base du test. Chaque candidat perd donc
 * sa colonne nulle, que la clé y tombe aujourd'hui ou non, et chaque recherche
 * qu'il servait garde un index :
 *
 * - les clés d'idempotence des séries et des lignes passent en tête de leur
 *   index unique, comme celle des séances (#2049) : `(idempotency_key,
 *   workout_line_id)` sert la même recherche d'une clé sous son parent, et la
 *   même unicité ;
 * - la séance en cours se cherche par `(ended_at, user_id, started_at)` :
 *   `ended_at is null`, le compte, puis la plus récente, comme avant ;
 * - `(user_id, category, name)` et `(workout_line_id, weight, reps)` partent :
 *   la liste des exercices d'un compte se lit par `(user_id, name)` et se trie
 *   comme avant (deux valeurs de `user_id`, le compte et le catalogue commun),
 *   et chaque somme de volume lit `is_completed`, que l'index des séries ne
 *   couvrait pas, par `(workout_line_id)` ;
 * - `created_at` des objectifs, minuteurs, calculs de macros, scores de Wilks
 *   et modèles n'est plus nullable : `latest()` y lit `(user_id, created_at)`
 *   dans l'ordre, qu'aucun autre index ne sert. Eloquent l'écrit toujours ;
 *   une ligne qui n'en aurait pas prend sa date de modification, sinon
 *   l'instant de la migration, et le serveur date une insertion qui l'omet.
 *
 * Les index se cherchent par leurs colonnes, pas par leur nom : une base plus
 * ancienne peut les avoir nommés autrement. Avant d'en retirer un, la table
 * garde un index qui commence par la colonne de la clé sans colonne nullable
 * derrière elle (`garderUnIndexPour()`) : MySQL refuse de retirer le seul
 * index qui puisse porter une clé (1553). Chaque ajout et chaque retrait
 * passent par leur propre appel, l'état relu entre deux
 * (`MigrationsRejouablesTest`).
 */
return new class() extends Migration
{
    /**
     * Les tables dont la clé d'idempotence passe en tête, et la colonne du
     * parent sous lequel elle est unique.
     *
     * @var array<string, string>
     */
    private const array CLES_D_IDEMPOTENCE = ['sets' => 'workout_line_id', 'workout_lines' => 'workout_id'];

    /**
     * Les index qui partent : la table, et ses colonnes, la clé en tête.
     *
     * @var list<array{0: string, 1: list<string>}>
     */
    private const array INDEX_RETIRES = [
        ['exercises', ['user_id', 'category', 'name']],
        ['sets', ['workout_line_id', 'weight', 'reps']],
    ];

    /**
     * Les tables dont `created_at` n'est plus nullable.
     *
     * @var list<string>
     */
    private const array DATES_DE_CREATION = ['goals', 'interval_timers', 'macro_calculations', 'wilks_scores', 'workout_templates'];

    public function up(): void
    {
        foreach (self::CLES_D_IDEMPOTENCE as $table => $parent) {
            if (! Schema::hasIndex($table, ['idempotency_key', $parent], 'unique')) {
                Schema::table($table, function (Blueprint $blueprint) use ($parent): void {
                    $blueprint->unique(['idempotency_key', $parent]);
                });
            }

            $this->retirer($table, [$parent, 'idempotency_key']);
        }

        if (! Schema::hasIndex('workouts', ['ended_at', 'user_id', 'started_at'])) {
            Schema::table('workouts', function (Blueprint $blueprint): void {
                $blueprint->index(['ended_at', 'user_id', 'started_at']);
            });
        }

        $this->retirer('workouts', ['user_id', 'ended_at', 'started_at']);

        foreach (self::INDEX_RETIRES as [$table, $colonnes]) {
            $this->retirer($table, $colonnes);
        }

        foreach (self::DATES_DE_CREATION as $table) {
            if (! $this->estNullable($table, 'created_at')) {
                continue;
            }

            DB::table($table)->whereNull('created_at')->update(['created_at' => DB::raw('coalesce(updated_at, current_timestamp)')]);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('created_at')->useCurrent()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::DATES_DE_CREATION as $table) {
            if (Schema::hasColumn($table, 'created_at') && ! $this->estNullable($table, 'created_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->timestamp('created_at')->nullable()->default(null)->change();
                });
            }
        }

        foreach (self::INDEX_RETIRES as [$table, $colonnes]) {
            if (! Schema::hasIndex($table, $colonnes)) {
                Schema::table($table, function (Blueprint $blueprint) use ($colonnes): void {
                    $blueprint->index($colonnes);
                });
            }
        }

        if (! Schema::hasIndex('workouts', ['user_id', 'ended_at', 'started_at'])) {
            Schema::table('workouts', function (Blueprint $blueprint): void {
                $blueprint->index(['user_id', 'ended_at', 'started_at'], 'workouts_user_id_ended_at_index');
            });
        }

        $this->retirer('workouts', ['ended_at', 'user_id', 'started_at']);

        foreach (self::CLES_D_IDEMPOTENCE as $table => $parent) {
            if (! Schema::hasIndex($table, [$parent, 'idempotency_key'], 'unique')) {
                Schema::table($table, function (Blueprint $blueprint) use ($parent): void {
                    $blueprint->unique([$parent, 'idempotency_key']);
                });
            }

            $this->retirer($table, ['idempotency_key', $parent]);
        }
    }

    /**
     * Retire chaque index qui porte exactement ces colonnes, dans cet ordre,
     * après s'être assuré que la clé de la première colonne garde un index.
     *
     * @param  list<string>  $colonnes
     */
    private function retirer(string $table, array $colonnes): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] !== $colonnes || $index['primary']) {
                continue;
            }

            $nom = $index['name'];
            $this->garderUnIndexPour($table, $colonnes[0], $nom);

            Schema::table($table, function (Blueprint $blueprint) use ($nom): void {
                $blueprint->dropIndex($nom);
            });
        }
    }

    /**
     * Pose un index sur la colonne quand elle porte une clé étrangère et
     * qu'aucun autre index que celui qui part ne commence par elle sans
     * colonne nullable derrière : la clé garde de quoi être portée, et
     * vérifiée sous 9.7.
     */
    private function garderUnIndexPour(string $table, string $colonne, string $partant): void
    {
        if (! Schema::hasForeignKey($table, [$colonne])) {
            return;
        }

        $nullables = array_column(array_filter(Schema::getColumns($table), static fn (array $description): bool => (bool) $description['nullable']), 'name');

        foreach (Schema::getIndexes($table) as $index) {
            $suite = array_slice($index['columns'], 1);

            if ($index['name'] !== $partant && ($index['columns'][0] ?? null) === $colonne && array_intersect($suite, $nullables) === []) {
                return;
            }
        }

        if (! Schema::hasIndex($table, [$colonne])) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonne): void {
                $blueprint->index([$colonne]);
            });
        }
    }

    /**
     * Vrai quand la colonne existe et admet NULL.
     */
    private function estNullable(string $table, string $colonne): bool
    {
        foreach (Schema::getColumns($table) as $description) {
            if ($description['name'] === $colonne) {
                return (bool) $description['nullable'];
            }
        }

        return false;
    }
};
