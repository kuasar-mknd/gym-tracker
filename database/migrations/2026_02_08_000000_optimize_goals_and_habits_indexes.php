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
        Schema::table('goals', function (Blueprint $table) {
            if (! Schema::hasIndex('goals', ['user_id', 'completed_at', 'created_at'])) {
                $table->index(['user_id', 'completed_at', 'created_at']);
            }
        });

        Schema::table('habits', function (Blueprint $table) {
            if (! Schema::hasIndex('habits', ['user_id', 'archived'])) {
                $table->index(['user_id', 'archived']);
            }
        });

        Schema::table('sets', function (Blueprint $table) {
            if (! Schema::hasIndex('sets', ['workout_line_id'])) {
                $table->index(['workout_line_id']);
            }
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_lines', ['exercise_id'])) {
                $table->index(['exercise_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('goals', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'completed_at', 'created_at']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('habits', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'archived']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('sets', function (Blueprint $table) {
                $table->dropIndex(['workout_line_id']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('workout_lines', function (Blueprint $table) {
                $table->dropIndex(['exercise_id']);
            });
        } catch (\Throwable $e) {
        }
    }
};
