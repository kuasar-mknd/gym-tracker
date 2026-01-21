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
        Schema::table('workouts', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            $table->index('workout_id');
            $table->index('exercise_id');
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->index('workout_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX workouts_user_id_index ON workouts');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX workout_lines_workout_id_index ON workout_lines');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX workout_lines_exercise_id_index ON workout_lines');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX sets_workout_line_id_index ON sets');
        } catch (\Throwable $e) {
        }
    }
};
