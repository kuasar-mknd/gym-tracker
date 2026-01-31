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
            Schema::table('workout_lines', function (Blueprint $table): void {
                $table->index(['workout_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('workout_template_lines', function (Blueprint $table): void {
                $table->index(['workout_template_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('workout_template_sets', function (Blueprint $table): void {
                $table->index(['workout_template_line_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('exercises', function (Blueprint $table): void {
                $table->index(['user_id', 'name']);
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
            Schema::table('workout_lines', function (Blueprint $table): void {
                $table->dropIndex(['workout_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }

        try {
            Schema::table('workout_template_lines', function (Blueprint $table): void {
                $table->dropIndex(['workout_template_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }

        try {
            Schema::table('workout_template_sets', function (Blueprint $table): void {
                $table->dropIndex(['workout_template_line_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }

        try {
            Schema::table('exercises', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'name']);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist or is needed by FK
        }
    }
};
