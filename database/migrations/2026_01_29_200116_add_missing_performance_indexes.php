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
            try {
                Schema::table('water_logs', function (Blueprint $table) {
                    if (Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                        $table->dropIndex('water_logs_user_id_consumed_at_index');
                    }
                });
            } catch (\Throwable $e) {
                // Ignore 1553: Cannot drop index ... needed in a foreign key constraint
                // We catch here because Laravel queues the dropIndex command and executes it after the closure,
                // so a try-catch inside the closure would not catch the database exception.
                if (! str_contains($e->getMessage(), '1553')) {
                    throw $e;
                }
            }
        }
    }
};
