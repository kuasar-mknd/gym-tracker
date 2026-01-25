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
        Schema::table('body_part_measurements', function (Blueprint $table) {
            if (! Schema::hasIndex('body_part_measurements', 'bpm_user_measured_index')) {
                $table->index(['user_id', 'measured_at'], 'bpm_user_measured_index');
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('supplement_logs', 'sup_logs_user_consumed_index')) {
                $table->index(['user_id', 'consumed_at'], 'sup_logs_user_consumed_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('body_part_measurements', function (Blueprint $table) {
            if (Schema::hasIndex('body_part_measurements', 'bpm_user_measured_index')) {
                $table->dropIndex('bpm_user_measured_index');
            }
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            if (Schema::hasIndex('supplement_logs', 'sup_logs_user_consumed_index')) {
                $table->dropIndex('sup_logs_user_consumed_index');
            }
        });
    }
};
