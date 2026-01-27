<?php

declare(strict_types=1);

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
        Schema::table('daily_journals', function (Blueprint $table) {
            if (! Schema::hasIndex('daily_journals', ['user_id', 'date'])) {
                $table->index(['user_id', 'date']);
            }
        });

        Schema::table('water_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('water_logs', ['user_id', 'consumed_at'])) {
                $table->index(['user_id', 'consumed_at']);
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('supplement_logs', ['user_id', 'consumed_at'])) {
                $table->index(['user_id', 'consumed_at']);
            }
        });

        Schema::table('body_part_measurements', function (Blueprint $table) {
            if (! Schema::hasIndex('body_part_measurements', ['user_id', 'measured_at'])) {
                $table->index(['user_id', 'measured_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_journals', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'date']);
        });

        Schema::table('water_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'consumed_at']);
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'consumed_at']);
        });

        Schema::table('body_part_measurements', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'measured_at']);
        });
    }
};
