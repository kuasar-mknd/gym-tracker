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
            if (! Schema::hasIndex('body_measurements', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('goals', function (Blueprint $table) {
            if (! Schema::hasIndex('goals', ['user_id'])) {
                $table->index('user_id');
            }
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            if (Schema::hasTable('body_measurements')) {
                Schema::table('body_measurements', function (Blueprint $table) {
                    $table->dropIndex(['user_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('goals')) {
                Schema::table('goals', function (Blueprint $table) {
                    $table->dropIndex(['user_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('goals')) {
                Schema::table('goals', function (Blueprint $table) {
                    $table->dropIndex(['exercise_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('personal_records')) {
                Schema::table('personal_records', function (Blueprint $table) {
                    $table->dropIndex(['workout_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('personal_records')) {
                Schema::table('personal_records', function (Blueprint $table) {
                    $table->dropIndex(['set_id']);
                });
            }
        } catch (\Throwable $e) {
        }
    }
};
