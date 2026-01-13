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
        Schema::table('workouts', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('workout_lines', function (Blueprint $table) {
            $table->dropIndex(['workout_id']);
            $table->dropIndex(['exercise_id']);
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->dropIndex(['workout_line_id']);
        });
    }
};
