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
        Schema::table('workout_template_lines', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_lines', ['workout_template_id', 'order'])) {
                $table->index(['workout_template_id', 'order']);
            }
        });

        Schema::table('workout_template_sets', function (Blueprint $table) {
            if (! Schema::hasIndex('workout_template_sets', ['workout_template_line_id', 'order'])) {
                $table->index(['workout_template_line_id', 'order']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('workout_template_lines', function (Blueprint $table) {
                $table->dropIndex(['workout_template_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index might not exist
        }

        try {
            Schema::table('workout_template_sets', function (Blueprint $table) {
                $table->dropIndex(['workout_template_line_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index might not exist
        }
    }
};
