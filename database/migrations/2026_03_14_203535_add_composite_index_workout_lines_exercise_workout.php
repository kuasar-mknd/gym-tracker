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
        Schema::table('workout_lines', function (Blueprint $table) {
            $table->index(['exercise_id', 'workout_id'], 'workout_lines_exercise_workout_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_lines', function (Blueprint $table) {
            $table->dropIndex('workout_lines_exercise_workout_idx');
        });
    }
};
