<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supplements', function (Blueprint $table) {
            if (! Schema::hasIndex('supplements', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('supplement_logs', ['user_id'])) {
                $table->index('user_id');
            }

            // Composite index for efficient retrieval of latest log per supplement
            if (! Schema::hasIndex('supplement_logs', ['supplement_id', 'consumed_at'])) {
                $table->index(['supplement_id', 'consumed_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX supplements_user_id_index ON supplements');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX supplement_logs_user_id_index ON supplement_logs');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX supplement_logs_supplement_id_consumed_at_index ON supplement_logs');
        } catch (\Throwable $e) {
        }
    }
};
