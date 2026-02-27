<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add index to interval_timers(user_id)
        Schema::table('interval_timers', function (Blueprint $table) {
            if (! Schema::hasIndex('interval_timers', ['user_id'])) {
                $table->index('user_id');
            }
        });

        // Add index to workout_template_lines(exercise_id)
        Schema::table('workout_template_lines', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_lines', ['exercise_id'])) {
                $table->index('exercise_id');
            }
        });

        // Add index to goals(user_id)
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
            Schema::table('interval_timers', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('workout_template_lines', function (Blueprint $table) {
                $table->dropIndex(['exercise_id']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('goals', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        } catch (\Throwable $e) {
        }
    }
};
