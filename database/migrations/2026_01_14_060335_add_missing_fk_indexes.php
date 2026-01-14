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
        Schema::table('body_measurements', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('workout_templates', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('workout_template_lines', function (Blueprint $table) {
            $table->index('workout_template_id');
            $table->index('exercise_id');
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            $table->index('workout_template_line_id');
        });

        Schema::table('personal_records', function (Blueprint $table) {
            // workout_id and set_id are nullable, but we index them for lookups
            $table->index('workout_id');
            $table->index('set_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('body_measurements', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('workout_templates', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('workout_template_lines', function (Blueprint $table) {
            $table->dropIndex(['workout_template_id']);
            $table->dropIndex(['exercise_id']);
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            $table->dropIndex(['workout_template_line_id']);
        });

        Schema::table('personal_records', function (Blueprint $table) {
            $table->dropIndex(['workout_id']);
            $table->dropIndex(['set_id']);
        });
    }
};
