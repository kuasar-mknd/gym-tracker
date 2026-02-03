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
        Schema::table('workouts', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            $table->index('workout_id');
            $table->index('exercise_id');
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->index('workout_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            if (Schema::hasTable('workouts')) {
                Schema::table('workouts', function (Blueprint $table) {
                    $table->dropIndex(['user_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('workout_lines')) {
                Schema::table('workout_lines', function (Blueprint $table) {
                    $table->dropIndex(['workout_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('workout_lines')) {
                Schema::table('workout_lines', function (Blueprint $table) {
                    $table->dropIndex(['exercise_id']);
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            if (Schema::hasTable('sets')) {
                Schema::table('sets', function (Blueprint $table) {
                    $table->dropIndex(['workout_line_id']);
                });
            }
        } catch (\Throwable $e) {
        }
    }
};
