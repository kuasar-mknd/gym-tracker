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
        Schema::table('interval_timers', function (Blueprint $table): void {
            if (! Schema::hasIndex('interval_timers', ['user_id', 'created_at'])) {
                $table->index(['user_id', 'created_at']);
            }
        });

        Schema::table('workout_templates', function (Blueprint $table): void {
            if (! Schema::hasIndex('workout_templates', ['user_id', 'created_at'])) {
                $table->index(['user_id', 'created_at']);
            }
        });

        Schema::table('goals', function (Blueprint $table): void {
            if (! Schema::hasIndex('goals', ['user_id', 'created_at'])) {
                $table->index(['user_id', 'created_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interval_timers', function (Blueprint $table): void {
            try {
                $table->dropIndex(['user_id', 'created_at']);
            } catch (\Throwable $e) {
                // Index might not exist
            }
        });

        Schema::table('workout_templates', function (Blueprint $table): void {
            try {
                $table->dropIndex(['user_id', 'created_at']);
            } catch (\Throwable $e) {
                // Index might not exist
            }
        });

        Schema::table('goals', function (Blueprint $table): void {
            try {
                $table->dropIndex(['user_id', 'created_at']);
            } catch (\Throwable $e) {
                // Index might not exist
            }
        });
    }
};
