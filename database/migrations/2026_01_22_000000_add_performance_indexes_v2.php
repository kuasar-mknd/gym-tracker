<?php

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
        Schema::table('habits', function (Blueprint $table) {
            // Composite index for fetching active habits for a user
            $table->index(['user_id', 'archived']);
        });

        Schema::table('exercises', function (Blueprint $table) {
            // Composite index for fetching and sorting exercises
            $table->index(['user_id', 'category', 'name']);
        });

        Schema::table('personal_records', function (Blueprint $table) {
            // Index for dashboard recent PRs
            $table->index(['user_id', 'achieved_at']);
        });

        Schema::table('body_measurements', function (Blueprint $table) {
            // Index for dashboard latest weight
            $table->index(['user_id', 'measured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('habits', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'archived']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('exercises', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'category', 'name']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'achieved_at']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('body_measurements', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'measured_at']);
            });
        } catch (\Throwable $e) {
        }
    }
};
