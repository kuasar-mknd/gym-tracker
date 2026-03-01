<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->decimal('volume', 15, 2)->default(0)->after('name');
        });

        // Populate initial volume for existing workouts
        // SECURITY: Static SQL - safe.
        DB::statement('
            UPDATE workouts SET volume = (
                SELECT COALESCE(SUM(sets.weight * sets.reps), 0)
                FROM workout_lines
                JOIN sets ON workout_lines.id = sets.workout_line_id
                WHERE workout_lines.workout_id = workouts.id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->dropColumn('volume');
        });
    }
};
