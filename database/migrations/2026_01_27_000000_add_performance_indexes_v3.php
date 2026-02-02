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
        try {
            if (Schema::hasTable('water_logs')) {
                Schema::table('water_logs', function (Blueprint $table): void {
                    $table->dropIndex(['user_id', 'consumed_at']);
                });
            }
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }

        try {
            if (Schema::hasTable('supplement_logs')) {
                Schema::table('supplement_logs', function (Blueprint $table): void {
                    $table->dropIndex(['user_id', 'consumed_at']);
                });
            }
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }

        try {
            if (Schema::hasTable('body_part_measurements')) {
                Schema::table('body_part_measurements', function (Blueprint $table): void {
                    $table->dropIndex(['user_id', 'measured_at']);
                });
            }
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }
    }
};
