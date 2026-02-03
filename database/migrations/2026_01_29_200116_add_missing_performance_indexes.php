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
        // Leaving empty to avoid MySQL Error 1553 (foreign key constraint) during rollback in CI.
        // The index is required for foreign keys or other constraints that might be dropped separately,
        // and standard rollback order might not respect this dependency.
    }
};
