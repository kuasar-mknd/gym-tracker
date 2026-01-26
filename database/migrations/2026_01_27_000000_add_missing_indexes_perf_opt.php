<?php

declare(strict_types=1);

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
        Schema::table('daily_journals', function (Blueprint $table) {
            // Optimize fetching journals by user and date sorting
            if (! Schema::hasIndex('daily_journals', ['user_id', 'date'])) {
                $table->index(['user_id', 'date']);
            }
        });

        Schema::table('goals', function (Blueprint $table) {
            // Optimize fetching active goals for dashboard
            if (! Schema::hasIndex('goals', ['user_id', 'completed_at', 'created_at'])) {
                $table->index(['user_id', 'completed_at', 'created_at']);
            }
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            // Optimize fetching workout lines in correct order
            if (! Schema::hasIndex('workout_lines', ['workout_id', 'order'])) {
                $table->index(['workout_id', 'order']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_journals', function (Blueprint $table) {
            try {
                $table->dropIndex(['user_id', 'date']);
            } catch (\Throwable $e) {
            }
        });

        Schema::table('goals', function (Blueprint $table) {
            try {
                $table->dropIndex(['user_id', 'completed_at', 'created_at']);
            } catch (\Throwable $e) {
            }
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            try {
                $table->dropIndex(['workout_id', 'order']);
            } catch (\Throwable $e) {
            }
        });
    }
};
