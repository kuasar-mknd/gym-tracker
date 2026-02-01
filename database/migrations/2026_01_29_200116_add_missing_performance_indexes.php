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
        // Use standard Schema facade checks for idempotency.
        // Outer try-catch ensures that even if hasIndex fails on some DB versions,
        // the migration process doesn't block.
        try {
            if (Schema::hasTable('water_logs') && ! Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                Schema::table('water_logs', function (Blueprint $table) {
                    $table->index(['user_id', 'consumed_at'], 'water_logs_user_id_consumed_at_index');
                });
            }
        } catch (\Throwable $e) {
            // Optimization failed, but don't block migrations
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op to avoid MySQL 1553 error: "Cannot drop index ... needed in a foreign key constraint".
        // This index is an optimization and doesn't affect functionality if left in place during rollback.
    }
};
