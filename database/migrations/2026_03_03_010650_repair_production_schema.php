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
        // Add workout_volume to workouts table if it doesn't exist
        try {
            if (Schema::hasTable('workouts') && ! Schema::hasColumn('workouts', 'workout_volume')) {
                Schema::table('workouts', function (Blueprint $table) {
                    $table->decimal('workout_volume', 15, 2)->default(0)->after('name');
                });
            }
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'already exists') && ! str_contains($e->getMessage(), '1060')) {
                throw $e;
            }
        }

        // Add total_volume to users table if it doesn't exist
        try {
            if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'total_volume')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->decimal('total_volume', 15, 2)->default(0)->after('password');
                });
            }
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'already exists') && ! str_contains($e->getMessage(), '1060')) {
                throw $e;
            }
        }

        // Add notes to workout_lines table if it doesn't exist
        try {
            if (Schema::hasTable('workout_lines') && ! Schema::hasColumn('workout_lines', 'notes')) {
                Schema::table('workout_lines', function (Blueprint $table) {
                    $table->text('notes')->nullable()->after('order');
                });
            }
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'already exists') && ! str_contains($e->getMessage(), '1060')) {
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration as this is a repair
    }
};
