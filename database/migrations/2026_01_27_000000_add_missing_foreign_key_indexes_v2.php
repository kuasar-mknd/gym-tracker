<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_records', function (Blueprint $table) {
            if ($this->shouldAddIndex('personal_records', 'workout_id', 'personal_records_workout_id_index')) {
                $table->index('workout_id', 'personal_records_workout_id_index');
            }

            if ($this->shouldAddIndex('personal_records', 'set_id', 'personal_records_set_id_index')) {
                $table->index('set_id', 'personal_records_set_id_index');
            }
        });

        Schema::table('sets', function (Blueprint $table) {
            if (!Schema::hasIndex('sets', 'sets_weight_index')) {
                $table->index('weight', 'sets_weight_index');
            }

            if (!Schema::hasIndex('sets', 'sets_reps_index')) {
                $table->index('reps', 'sets_reps_index');
            }

            if ($this->shouldAddIndex('sets', 'workout_line_id', 'sets_workout_line_id_index')) {
                $table->index('workout_line_id', 'sets_workout_line_id_index');
            }
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            if ($this->shouldAddIndex('workout_lines', 'exercise_id', 'workout_lines_exercise_id_index')) {
                $table->index('exercise_id', 'workout_lines_exercise_id_index');
            }

            if ($this->shouldAddIndex('workout_lines', 'workout_id', 'workout_lines_workout_id_index')) {
                $table->index('workout_id', 'workout_lines_workout_id_index');
            }
        });

        Schema::table('body_measurements', function (Blueprint $table) {
            if (!Schema::hasIndex('body_measurements', 'body_measurements_weight_index')) {
                $table->index('weight', 'body_measurements_weight_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_records', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'personal_records', 'personal_records_workout_id_index');
            $this->dropIndexIfExists($table, 'personal_records', 'personal_records_set_id_index');
        });

        Schema::table('sets', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'sets', 'sets_weight_index');
            $this->dropIndexIfExists($table, 'sets', 'sets_reps_index');
            $this->dropIndexIfExists($table, 'sets', 'sets_workout_line_id_index');
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'workout_lines', 'workout_lines_exercise_id_index');
            $this->dropIndexIfExists($table, 'workout_lines', 'workout_lines_workout_id_index');
        });

        Schema::table('body_measurements', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'body_measurements', 'body_measurements_weight_index');
        });
    }

    /**
     * Determine if an index should be added by checking for both the explicit name
     * and the standard Laravel foreign key index name.
     */
    protected function shouldAddIndex(string $table, string $column, string $explicitName): bool
    {
        $standardName = "{$table}_{$column}_foreign";

        return !Schema::hasIndex($table, $explicitName) && !Schema::hasIndex($table, $standardName);
    }

    /**
     * Drop an index if it exists.
     */
    protected function dropIndexIfExists(Blueprint $table, string $tableName, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            $table->dropIndex($indexName);
        }
    }
};
