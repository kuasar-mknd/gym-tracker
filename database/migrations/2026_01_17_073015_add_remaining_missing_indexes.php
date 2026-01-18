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
        Schema::table('plates', function (Blueprint $table) {
            if (! Schema::hasIndex('plates', ['user_id'])) {
                $table->index('user_id');
            }
        });

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
        if (Schema::hasTable('plates')) {
            Schema::table('plates', function (Blueprint $table) {
                if (Schema::hasIndex('plates', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }

        if (Schema::hasTable('personal_records')) {
            Schema::table('personal_records', function (Blueprint $table) {
                if (Schema::hasIndex('personal_records', ['workout_id'])) {
                    $table->dropIndex(['workout_id']);
                }
                if (Schema::hasIndex('personal_records', ['set_id'])) {
                    $table->dropIndex(['set_id']);
                }
            });
        }
    }
};
