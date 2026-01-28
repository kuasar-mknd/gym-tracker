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
        // Water Logs: Filter by user and consumed_at
        Schema::table('water_logs', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at']);
        });

        // Supplement Logs: Filter by user and consumed_at (Usage History)
        Schema::table('supplement_logs', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at']);
        });

        // Body Part Measurements: Filter by user and measured_at (Sorting without part filter)
        Schema::table('body_part_measurements', function (Blueprint $table) {
            $table->index(['user_id', 'measured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('water_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('supplement_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('body_part_measurements', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'measured_at']);
            });
        } catch (\Throwable $e) {
        }
    }
};
