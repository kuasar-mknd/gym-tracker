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
        Schema::table('goals', function (Blueprint $table) {
            // Check for standard index name to avoid duplication/errors
            if (! Schema::hasIndex('goals', ['exercise_id'])) {
                $table->index('exercise_id');
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

        Schema::table('exercises', function (Blueprint $table) {
            if (! Schema::hasIndex('exercises', ['user_id'])) {
                $table->index('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('goals')) {
            Schema::table('goals', function (Blueprint $table) {
                if (Schema::hasIndex('goals', ['exercise_id'])) {
                    $table->dropIndex(['exercise_id']);
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

        if (Schema::hasTable('exercises')) {
            Schema::table('exercises', function (Blueprint $table) {
                if (Schema::hasIndex('exercises', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }
    }
};
