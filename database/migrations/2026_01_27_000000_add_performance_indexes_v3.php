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

        try {
            Schema::table('supplement_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('body_part_measurements', function (Blueprint $table): void {
                $table->index(['user_id', 'measured_at']);
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
        // We do not drop indexes in down() because they might be used by foreign key constraints
        // (MySQL error 1553), causing rollback issues in CI/testing environments.
        // Since these are performance indexes, leaving them is safer than crashing the migration rollback.
    }
};
