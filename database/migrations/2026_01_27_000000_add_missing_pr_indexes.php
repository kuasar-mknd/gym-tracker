<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_records', function (Blueprint $table) {
            if (! Schema::hasIndex('personal_records', ['workout_id'])) {
                $table->index('workout_id');
            }
            if (! Schema::hasIndex('personal_records', ['set_id'])) {
                $table->index('set_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_records', function (Blueprint $table) {
            try {
                $table->dropIndex(['workout_id']);
            } catch (\Throwable $e) {}

            try {
                $table->dropIndex(['set_id']);
            } catch (\Throwable $e) {}
        });
    }
};
