<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cinq index simples n'étaient que le début d'un autre index de leur table
 * (#2350) : chaque écriture en tenait une entrée de plus, pour des lectures
 * que l'index plus long sert à l'identique. `sets` (workout_line_id) sous
 * (workout_line_id, order), `workout_lines` (workout_id) sous
 * (workout_id, order) et (workout_id, exercise_id), (exercise_id) sous
 * (exercise_id, workout_id), `habits` (user_id) sous (user_id, archived),
 * `habit_logs` (habit_id) sous l'unique (habit_id, date).
 *
 * Un index ne part que si un autre index, plus long, commence par sa colonne
 * et n'a que des colonnes obligatoires derrière elle : la clé étrangère garde
 * de quoi être portée (`.ai/rules/database.md`). Les index se trouvent par
 * leurs colonnes, pas par leur nom. `down()` les recrée sous leur nom
 * d'origine ; chaque ordre juge l'état de sa table.
 */
return new class() extends Migration
{
    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEX = [
        'sets_workout_line_id_index' => ['sets', 'workout_line_id'],
        'workout_lines_workout_id_index' => ['workout_lines', 'workout_id'],
        'workout_lines_exercise_id_index' => ['workout_lines', 'exercise_id'],
        'habits_user_id_index' => ['habits', 'user_id'],
        'habit_logs_habit_id_index' => ['habit_logs', 'habit_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEX as [$table, $colonne]) {
            $nom = Schema::hasIndex($table, [$colonne]) ? $this->indexSimple($table, $colonne) : null;

            if ($nom === null || ! $this->unIndexPlusLongCommencePar($table, $colonne)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($nom): void {
                $blueprint->dropIndex($nom);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEX as $nom => [$table, $colonne]) {
            if ($this->indexSimple($table, $colonne) !== null) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($nom, $colonne): void {
                $blueprint->index($colonne, $nom);
            });
        }
    }

    private function indexSimple(string $table, string $colonne): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === [$colonne] && ! $index['primary'] && ! $index['unique']) {
                return $index['name'];
            }
        }

        return null;
    }

    private function unIndexPlusLongCommencePar(string $table, string $colonne): bool
    {
        $nulles = [];

        foreach (Schema::getColumns($table) as $definition) {
            $nulles[$definition['name']] = $definition['nullable'];
        }

        foreach (Schema::getIndexes($table) as $index) {
            $colonnes = $index['columns'];

            if (count($colonnes) < 2 || $colonnes[0] !== $colonne) {
                continue;
            }

            $derriere = array_slice($colonnes, 1);

            if (array_filter($derriere, static fn (string $autre): bool => $nulles[$autre] ?? true) === []) {
                return true;
            }
        }

        return false;
    }
};
