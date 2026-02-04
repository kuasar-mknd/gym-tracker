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
        Schema::table('habits', function (Blueprint $table) {
            if (! Schema::hasIndex('habits', 'habits_user_id_archived_index')) {
                // Composite index for fetching active habits for a user
                $table->index(['user_id', 'archived']);
            }
        });

        Schema::table('exercises', function (Blueprint $table) {
            if (! Schema::hasIndex('exercises', 'exercises_user_id_category_name_index')) {
                // Composite index for fetching and sorting exercises
                $table->index(['user_id', 'category', 'name']);
            }
        });

        Schema::table('personal_records', function (Blueprint $table) {
            if (! Schema::hasIndex('personal_records', 'personal_records_user_id_achieved_at_index')) {
                // Index for dashboard recent PRs
                $table->index(['user_id', 'achieved_at']);
            }
        });

        Schema::table('body_measurements', function (Blueprint $table) {
            if (! Schema::hasIndex('body_measurements', 'body_measurements_user_id_measured_at_index')) {
                // Index for dashboard latest weight
                $table->index(['user_id', 'measured_at']);
            }
        });
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
