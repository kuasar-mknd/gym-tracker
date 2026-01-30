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
        if (Schema::hasTable('water_logs')) {
            Schema::table('water_logs', function (Blueprint $table) {
                // Check by index name first
                if (! Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                    // Also check by columns to be safe (Laravel convention)
                    if (! Schema::hasIndex('water_logs', ['user_id', 'consumed_at'])) {
                        $table->index(['user_id', 'consumed_at'], 'water_logs_user_id_consumed_at_index');
                    }
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
            Schema::table('water_logs', function (Blueprint $table) {
                // We don't want to blindly drop it because other migrations might have added it
                // But for reversibility of *this* migration, we can try to drop it if it exists.
                // However, since this migration is "fixing" a duplicate add, down() is tricky.
                // Safest is to leave it or try dropIndex.
                try {
                    $table->dropIndex('water_logs_user_id_consumed_at_index');
                } catch (\Throwable $e) {
                    // Ignore
                }
            });
        }
    }
};
