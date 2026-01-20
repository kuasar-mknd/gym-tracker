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
        Schema::table('supplements', function (Blueprint $table) {
            if (Schema::hasIndex('supplements', ['user_id'])) {
                $table->dropIndex(['user_id']);
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (Schema::hasIndex('supplement_logs', ['user_id'])) {
                $table->dropIndex(['user_id']);
            }

            if (Schema::hasIndex('supplement_logs', ['supplement_id', 'consumed_at'])) {
                $table->dropIndex(['supplement_id', 'consumed_at']);
            }
        });
    }
};
