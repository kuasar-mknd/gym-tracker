<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Indexes for workout templates performance
        Schema::table('workout_template_lines', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_lines', ['workout_template_id'])) {
                $table->index('workout_template_id');
            }
            if (! Schema::hasIndex('workout_template_lines', ['exercise_id'])) {
                $table->index('exercise_id');
            }
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_sets', ['workout_template_line_id'])) {
                $table->index('workout_template_line_id');
            }
        });

        // Index for goals filtering by user
        Schema::table('goals', function (Blueprint $table) {
            if (! Schema::hasIndex('goals', ['user_id'])) {
                $table->index('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX workout_template_lines_workout_template_id_index ON workout_template_lines');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX workout_template_lines_exercise_id_index ON workout_template_lines');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX workout_template_sets_workout_template_line_id_index ON workout_template_sets');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX goals_user_id_index ON goals');
        } catch (\Throwable $e) {
        }
    }
};
