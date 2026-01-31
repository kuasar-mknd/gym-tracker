<?php

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
        try {
            Schema::table('water_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Index already exists, ignore
        }

        try {
            Schema::table('supplement_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Index already exists, ignore
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Indexes are typically dropped automatically when the table is dropped,
        // and these tables might be rolled back completely.
        // However, if we rollback just this migration, we need to be careful
        // about Foreign Key constraints in MySQL that might have latched onto
        // this new index instead of the original 'user_id' index.

        try {
            Schema::table('water_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Ignore constraint violation during rollback if MySQL refuses to drop it
            // because it's actively using it for the FK.
            // This is expected in environments where the original index might have been
            // replaced by this composite index.
        }

        try {
            Schema::table('supplement_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Ignore constraint violation
        }
    }
};
