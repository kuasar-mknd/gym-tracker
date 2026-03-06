<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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
            if (! Schema::hasColumn('workout_lines', 'notes')) {
                Schema::table('workout_lines', function (Blueprint $table) {
                    $table->text('notes')->nullable()->after('order');
                });
            }
        } catch (QueryException $e) {
            // If the error is "Duplicate column name", we can safely ignore it
            if (str_contains($e->getMessage(), '1060') || str_contains($e->getMessage(), 'already exists')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('workout_lines', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        } catch (QueryException $e) {
            // Ignore if column doesn't exist
        }
    }
};
