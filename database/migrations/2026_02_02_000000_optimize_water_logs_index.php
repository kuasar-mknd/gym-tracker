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
        try {
            if (Schema::hasTable('water_logs') && ! Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                Schema::table('water_logs', function (Blueprint $table): void {
                    $table->index(['user_id', 'consumed_at'], 'water_logs_user_id_consumed_at_index');
                });
            }
        } catch (\Throwable $e) {
            // Silently ignore
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('water_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Ignore if index doesn't exist or is needed by FK
        }
    }
};
