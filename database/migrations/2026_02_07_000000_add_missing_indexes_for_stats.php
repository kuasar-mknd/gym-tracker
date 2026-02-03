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
        Schema::table('body_measurements', function (Blueprint $table) {
            // Index for stats history queries (filtering by user and sorting by date)
            if (! Schema::hasIndex('body_measurements', 'body_measurements_user_id_measured_at_index')) {
                $table->index(['user_id', 'measured_at']);
            }
        });

        Schema::table('personal_records', function (Blueprint $table) {
            // Index for dashboard recent PRs (filtering by user and sorting by date)
            if (! Schema::hasIndex('personal_records', 'personal_records_user_id_achieved_at_index')) {
                $table->index(['user_id', 'achieved_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('body_measurements', function (Blueprint $table) {
            try {
                $table->dropIndex(['user_id', 'measured_at']);
            } catch (\Throwable $e) {
                // Ignore MySQL 1553 error (foreign key constraint) during rollback
            }
        });

        Schema::table('personal_records', function (Blueprint $table) {
            try {
                $table->dropIndex(['user_id', 'achieved_at']);
            } catch (\Throwable $e) {
                // Ignore MySQL 1553 error
            }
        });
    }
};
