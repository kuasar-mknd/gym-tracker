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
        Schema::table('body_measurements', function (Blueprint $table) {
            if (! Schema::hasIndex('body_measurements', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('goals', function (Blueprint $table) {
            if (! Schema::hasIndex('goals', ['user_id'])) {
                $table->index('user_id');
            }
            if (! Schema::hasIndex('goals', ['exercise_id'])) {
                $table->index('exercise_id');
            }
        });

        Schema::table('personal_records', function (Blueprint $table) {
            if (! Schema::hasIndex('personal_records', ['workout_id'])) {
                $table->index('workout_id');
            }
            if (! Schema::hasIndex('personal_records', ['set_id'])) {
                $table->index('set_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX body_measurements_user_id_index ON body_measurements');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX goals_user_id_index ON goals');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX goals_exercise_id_index ON goals');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX personal_records_workout_id_index ON personal_records');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX personal_records_set_id_index ON personal_records');
        } catch (\Throwable $e) {
        }
    }
};
