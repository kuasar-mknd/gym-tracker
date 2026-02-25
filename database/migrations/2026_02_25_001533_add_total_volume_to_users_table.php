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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('total_volume', 15, 2)->default(0)->after('longest_streak');
        });

        // Populate initial total_volume
        // SECURITY: Static SQL - safe.
        DB::statement('
            UPDATE users SET total_volume = (
                SELECT COALESCE(SUM(sets.weight * sets.reps), 0)
                FROM workouts
                JOIN workout_lines ON workouts.id = workout_lines.workout_id
                JOIN sets ON workout_lines.id = sets.workout_line_id
                WHERE workouts.user_id = users.id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('total_volume');
        });
    }
};
