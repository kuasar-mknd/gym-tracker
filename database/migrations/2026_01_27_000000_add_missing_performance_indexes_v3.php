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
            // Composite index for querying logs by user and date/time (e.g. usage history)
            // Also covers queries by user_id alone
            if (! Schema::hasIndex('water_logs', ['user_id', 'consumed_at'])) {
                $table->index(['user_id', 'consumed_at']);
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            // Composite index for querying logs by user and date/time (e.g. usage history)
            // This is more specific than the existing user_id index for range queries
            if (! Schema::hasIndex('supplement_logs', ['user_id', 'consumed_at'])) {
                $table->index(['user_id', 'consumed_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('water_logs', function (Blueprint $table) {
            if (Schema::hasIndex('water_logs', ['user_id', 'consumed_at'])) {
                $table->dropIndex(['user_id', 'consumed_at']);
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (Schema::hasIndex('supplement_logs', ['user_id', 'consumed_at'])) {
                $table->dropIndex(['user_id', 'consumed_at']);
            }
        });
    }
};
