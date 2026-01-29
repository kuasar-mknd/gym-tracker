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
        Schema::table('workout_lines', function (Blueprint $table) {
            // Index for ordering lines within a workout
            if (! Schema::hasIndex('workout_lines', ['workout_id', 'order'])) {
                $table->index(['workout_id', 'order']);
            }
        });

        Schema::table('workout_template_lines', function (Blueprint $table) {
            // Index for ordering lines within a template
            if (! Schema::hasIndex('workout_template_lines', ['workout_template_id', 'order'])) {
                $table->index(['workout_template_id', 'order']);
            }
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            // Index for ordering sets within a template line
            if (! Schema::hasIndex('workout_template_sets', ['workout_template_line_id', 'order'])) {
                $table->index(['workout_template_line_id', 'order']);
            }
        });

        Schema::table('exercises', function (Blueprint $table) {
            // Index for sorting exercises by name for a user (ignoring category)
            if (! Schema::hasIndex('exercises', ['user_id', 'name'])) {
                $table->index(['user_id', 'name']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_lines', function (Blueprint $table) {
            // Ensure the FK index exists before dropping the composite one
            if (! Schema::hasIndex('workout_lines', ['workout_id'])) {
                $table->index('workout_id');
            }
            $table->dropIndex(['workout_id', 'order']);
        });

        Schema::table('workout_template_lines', function (Blueprint $table) {
            // Ensure the FK index exists before dropping the composite one
            if (! Schema::hasIndex('workout_template_lines', ['workout_template_id'])) {
                $table->index('workout_template_id');
            }
            $table->dropIndex(['workout_template_id', 'order']);
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            // Ensure the FK index exists before dropping the composite one
            if (! Schema::hasIndex('workout_template_sets', ['workout_template_line_id'])) {
                $table->index('workout_template_line_id');
            }
            $table->dropIndex(['workout_template_line_id', 'order']);
        });

        Schema::table('exercises', function (Blueprint $table) {
            // Ensure the FK index exists before dropping the composite one
            if (! Schema::hasIndex('exercises', ['user_id'])) {
                $table->index('user_id');
            }
            $table->dropIndex(['user_id', 'name']);
        });
    }
};
