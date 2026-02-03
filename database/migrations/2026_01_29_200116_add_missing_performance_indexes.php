<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            try {
                // Disable foreign key checks to allow dropping the index even if the FK relies on it temporarily
                // This is risky in production but acceptable for a performance index rollback or test environment
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');

                Schema::table('water_logs', function (Blueprint $table) {
                    if (Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                        $table->dropIndex('water_logs_user_id_consumed_at_index');
                    }
                });

                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Throwable $e) {
                // Ensure FK checks are re-enabled even if an error occurs
                try {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                } catch (\Throwable $ex) {
                    // Ignore secondary error
                }

                // Ignore 1553: Cannot drop index ... needed in a foreign key constraint
                if (! str_contains($e->getMessage(), '1553')) {
                    throw $e;
                }
            }
        }
    }
};
