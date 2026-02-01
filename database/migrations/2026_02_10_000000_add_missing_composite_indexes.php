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
        try {
            Schema::table('interval_timers', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            // Ignore 1553: Cannot drop index ... needed in a foreign key constraint
        }

        try {
            Schema::table('workout_templates', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            // Ignore 1553: Cannot drop index ... needed in a foreign key constraint
        }

        try {
            Schema::table('goals', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            // Ignore 1553: Cannot drop index ... needed in a foreign key constraint
        }
    }
};
