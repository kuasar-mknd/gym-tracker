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
            if (! Schema::hasIndex('goals', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('body_measurements', function (Blueprint $table) {
            if (! Schema::hasIndex('body_measurements', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('workout_templates', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_templates', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('workout_template_lines', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_lines', ['workout_template_id'])) {
                $table->index('workout_template_id');
            }
            if (! Schema::hasIndex('workout_template_lines', ['exercise_id'])) {
                $table->index('exercise_id');
            }
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_sets', ['workout_template_line_id'])) {
                $table->index('workout_template_line_id');
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
                if (Schema::hasIndex('goals', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }

        if (Schema::hasTable('body_measurements')) {
            Schema::table('body_measurements', function (Blueprint $table) {
                if (Schema::hasIndex('body_measurements', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }

        if (Schema::hasTable('workout_templates')) {
            Schema::table('workout_templates', function (Blueprint $table) {
                if (Schema::hasIndex('workout_templates', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }

        if (Schema::hasTable('workout_template_lines')) {
            Schema::table('workout_template_lines', function (Blueprint $table) {
                if (Schema::hasIndex('workout_template_lines', ['workout_template_id'])) {
                    $table->dropIndex(['workout_template_id']);
                }
                if (Schema::hasIndex('workout_template_lines', ['exercise_id'])) {
                    $table->dropIndex(['exercise_id']);
                }
            });
        }

        if (Schema::hasTable('workout_template_sets')) {
            Schema::table('workout_template_sets', function (Blueprint $table) {
                if (Schema::hasIndex('workout_template_sets', ['workout_template_line_id'])) {
                    $table->dropIndex(['workout_template_line_id']);
                }
            });
        }
    }
};
