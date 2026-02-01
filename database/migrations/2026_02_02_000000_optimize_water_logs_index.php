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
        if (! Schema::hasTable('water_logs')) {
            return;
        }

        $conn = Schema::getConnection();
        $schemaBuilder = $conn->getSchemaBuilder();
        $indexName = 'water_logs_user_id_consumed_at_index';

        try {
            if (! $schemaBuilder->hasIndex('water_logs', $indexName)) {
                Schema::table('water_logs', function (Blueprint $table) use ($indexName): void {
                    $table->index(['user_id', 'consumed_at'], $indexName);
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
