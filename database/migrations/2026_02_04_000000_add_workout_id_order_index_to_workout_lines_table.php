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
            // Add composite index for ordered retrieval of workout lines
            if (! Schema::hasIndex('workout_lines', ['workout_id', 'order'])) {
                $table->index(['workout_id', 'order']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('workout_lines', function (Blueprint $table) {
                $table->dropIndex(['workout_id', 'order']);
            });
        } catch (\Throwable $e) {
            // Index might not exist or be needed by FK
        }
    }
};
