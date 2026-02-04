<?php

declare(strict_types=1);

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
        // 1. sets(workout_line_id)
        try {
            Schema::table('sets', function (Blueprint $table) {
                if (! Schema::hasIndex('sets', ['workout_line_id'])) {
                    $table->index('workout_line_id');
                }
            });
        } catch (\Throwable $e) {
            // Index might already exist
        }

        // 2. workout_lines(exercise_id)
        try {
            Schema::table('workout_lines', function (Blueprint $table) {
                if (! Schema::hasIndex('workout_lines', ['exercise_id'])) {
                    $table->index('exercise_id');
                }
            });
        } catch (\Throwable $e) {
            // Index might already exist
        }

        // 3. habits(user_id, archived) - Optimization for HabitController::index
        try {
            Schema::table('habits', function (Blueprint $table) {
                if (! Schema::hasIndex('habits', ['user_id', 'archived'])) {
                    $table->index(['user_id', 'archived']);
                }
            });
        } catch (\Throwable $e) {
            // Index might already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. sets(workout_line_id)
        try {
            Schema::table('sets', function (Blueprint $table) {
                // Only drop if it's not the FK constraint index (which we can't easily distinguish here without checking names)
                // But usually, dropping by column array targets the index.
                // NOTE: If the index is needed by FK, MySQL might refuse to drop it unless we drop FK first.
                // Since this migration ADDS it if missing, we should try to drop it if we added it.
                // However, determining if *we* added it or it was implicit is hard.
                // Safe approach: Try to drop, ignore if fails.
                $table->dropIndex(['workout_line_id']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }

        // 2. workout_lines(exercise_id)
        try {
            Schema::table('workout_lines', function (Blueprint $table) {
                $table->dropIndex(['exercise_id']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }

        // 3. habits(user_id, archived)
        try {
            Schema::table('habits', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'archived']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }
    }
};
