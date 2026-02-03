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
            Schema::table('interval_timers', function (Blueprint $table): void {
                $table->index(['user_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('body_measurements', function (Blueprint $table): void {
                $table->index(['user_id', 'measured_at']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('goals', function (Blueprint $table): void {
                $table->index(['user_id', 'created_at']);
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
            Schema::table('interval_timers', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist
        }

        try {
            Schema::table('body_measurements', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'measured_at']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist
        }

        try {
            Schema::table('goals', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist
        }
    }
};
