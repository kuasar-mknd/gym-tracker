<?php

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
        Schema::table('water_logs', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at']);
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at']);
        });
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
