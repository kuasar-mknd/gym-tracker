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
        Schema::table('water_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('water_logs', 'water_logs_user_id_consumed_at_index')) {
                $table->index(['user_id', 'consumed_at']);
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('supplement_logs', 'supplement_logs_user_id_consumed_at_index')) {
                $table->index(['user_id', 'consumed_at']);
            }
        });

        Schema::table('body_part_measurements', function (Blueprint $table) {
            if (! Schema::hasIndex('body_part_measurements', 'body_part_measurements_user_id_measured_at_index')) {
                $table->index(['user_id', 'measured_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('water_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Ignore if index is needed in a foreign key constraint (Error 1553)
        }

        try {
            Schema::table('supplement_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }

        try {
            Schema::table('body_part_measurements', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'measured_at']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }
    }
};
