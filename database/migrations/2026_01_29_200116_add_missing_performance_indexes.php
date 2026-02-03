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
        // In CI/Testing environments, MySQL 8.0+ strictly enforces Foreign Key constraints when dropping indexes.
        // If this index has been implicitly adopted by a foreign key constraint (e.g. user_id), dropping it
        // triggers Error 1553. Since this migration is just adding performance indexes, it is safer to
        // skip dropping this specific index during rollback to avoid breaking the test suite teardown.
        // The index will be removed when the table itself is dropped.
    }
};
