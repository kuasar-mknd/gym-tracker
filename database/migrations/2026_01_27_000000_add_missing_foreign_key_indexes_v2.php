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
            // Explicit indexes for nullable foreign keys
            // We check for both our explicit name and the standard Laravel foreign key index name
            if (! Schema::hasIndex('personal_records', 'personal_records_workout_id_index') && ! Schema::hasIndex('personal_records', 'personal_records_workout_id_foreign')) {
                $table->index('workout_id', 'personal_records_workout_id_index');
            }
            if (! Schema::hasIndex('personal_records', 'personal_records_set_id_index') && ! Schema::hasIndex('personal_records', 'personal_records_set_id_foreign')) {
                $table->index('set_id', 'personal_records_set_id_index');
            }
        });

        Schema::table('sets', function (Blueprint $table) {
            // Indexes for aggregations (MAX, SUM)
            if (! Schema::hasIndex('sets', 'sets_weight_index')) {
                $table->index('weight', 'sets_weight_index');
            }
            if (! Schema::hasIndex('sets', 'sets_reps_index')) {
                $table->index('reps', 'sets_reps_index');
            }

            // Explicit index for workout_line_id
            // Check for explicit and standard FK index
             if (! Schema::hasIndex('sets', 'sets_workout_line_id_index') && ! Schema::hasIndex('sets', 'sets_workout_line_id_foreign')) {
                $table->index('workout_line_id', 'sets_workout_line_id_index');
            }
        });

        Schema::table('workout_lines', function (Blueprint $table) {
             if (! Schema::hasIndex('workout_lines', 'workout_lines_exercise_id_index') && ! Schema::hasIndex('workout_lines', 'workout_lines_exercise_id_foreign')) {
                $table->index('exercise_id', 'workout_lines_exercise_id_index');
            }
             if (! Schema::hasIndex('workout_lines', 'workout_lines_workout_id_index') && ! Schema::hasIndex('workout_lines', 'workout_lines_workout_id_foreign')) {
                $table->index('workout_id', 'workout_lines_workout_id_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_records', function (Blueprint $table) {
            if (Schema::hasIndex('personal_records', 'personal_records_workout_id_index')) {
                $table->dropIndex('personal_records_workout_id_index');
            }
            if (Schema::hasIndex('personal_records', 'personal_records_set_id_index')) {
                $table->dropIndex('personal_records_set_id_index');
            }
        });

        Schema::table('sets', function (Blueprint $table) {
            if (Schema::hasIndex('sets', 'sets_weight_index')) {
                $table->dropIndex('sets_weight_index');
            }
            if (Schema::hasIndex('sets', 'sets_reps_index')) {
                $table->dropIndex('sets_reps_index');
            }
            if (Schema::hasIndex('sets', 'sets_workout_line_id_index')) {
                $table->dropIndex('sets_workout_line_id_index');
            }
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            if (Schema::hasIndex('workout_lines', 'workout_lines_exercise_id_index')) {
                $table->dropIndex('workout_lines_exercise_id_index');
            }
            if (Schema::hasIndex('workout_lines', 'workout_lines_workout_id_index')) {
                $table->dropIndex('workout_lines_workout_id_index');
            }
        });
    }
};
