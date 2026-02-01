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
        // Fix: Check if table exists before adding index to avoid errors in tests/CI
        if (Schema::hasTable('water_logs')) {
            Schema::table('water_logs', function (Blueprint $table) {
                if (! Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                    $table->index(['user_id', 'consumed_at'], 'water_logs_user_id_consumed_at_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('water_logs')) {
            // To safely drop an index that might be used by a Foreign Key (MySQL 1553),
            // we must temporarily drop the FK, then the index, then restore the FK.

            // 1. Drop Foreign Key
            try {
                Schema::table('water_logs', function (Blueprint $table) {
                    $table->dropForeign(['user_id']);
                });
            } catch (\Throwable $e) {
                // Ignore if FK doesn't exist
            }

            // 2. Drop Index
            try {
                Schema::table('water_logs', function (Blueprint $table) {
                    if (Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                        $table->dropIndex('water_logs_user_id_consumed_at_index');
                    }
                });
            } catch (\Throwable $e) {
                // Ignore if index doesn't exist
            }

            // 3. Restore Foreign Key
            try {
                Schema::table('water_logs', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
                // Ignore if FK already exists
            }
        }
    }
};
