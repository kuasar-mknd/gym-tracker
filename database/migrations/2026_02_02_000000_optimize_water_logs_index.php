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
            Schema::table('water_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally empty to avoid FK constraint errors during rollback
    }
};
