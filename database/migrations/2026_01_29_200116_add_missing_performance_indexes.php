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
        // Ensure table exists before trying to add index
        if (! Schema::hasTable('water_logs')) {
            return;
        }

        // Use more robust check for index existence
        $conn = Schema::getConnection();
        $schemaBuilder = $conn->getSchemaBuilder();
        $indexName = 'water_logs_user_id_consumed_at_index';

        try {
            if (! $schemaBuilder->hasIndex('water_logs', $indexName)) {
                Schema::table('water_logs', function (Blueprint $table) use ($indexName) {
                    $table->index(['user_id', 'consumed_at'], $indexName);
                });
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during index creation (e.g., already exists or FK conflict)
            // This ensures migrations continue even if optimization fails.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op to avoid MySQL 1553 error: "Cannot drop index ... needed in a foreign key constraint".
        // This index is an optimization and doesn't affect functionality if left in place during rollback.
    }
};
