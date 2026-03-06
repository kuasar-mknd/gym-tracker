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
        try {
            Schema::table('sets', function (Blueprint $table): void {
                if (! Schema::hasIndex('sets', ['workout_line_id', 'weight', 'reps'])) {
                    $table->index(['workout_line_id', 'weight', 'reps']);
                }
            });
        } catch (\Throwable $e) {
            // Index might already exist or overlap
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('sets', function (Blueprint $table): void {
                $table->dropIndex(['workout_line_id', 'weight', 'reps']);
            });
        } catch (\Throwable $e) {
            // Index might not exist or be needed by FK
        }
    }
};
