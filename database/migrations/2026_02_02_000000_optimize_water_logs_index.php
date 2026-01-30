<?php

declare(strict_types=1);

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
            // Composite index for fetching and sorting by consumed_at (history/charts)
            // This also covers queries filtering only by user_id
            if (! Schema::hasIndex('water_logs', ['user_id', 'consumed_at'])) {
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
            // Use try/catch or just dropIndex which might throw if not exists
            try {
                $table->dropIndex(['user_id', 'consumed_at']);
            } catch (\Throwable $e) {
                // Ignore if index doesn't exist
            }
        });
    }
};
