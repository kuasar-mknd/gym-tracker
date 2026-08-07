<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs counters that have been drifting upward since the feature shipped.
 *
 * users.total_volume and workouts.workout_volume were maintained by model
 * events on Set, but sets.workout_line_id is ON DELETE CASCADE: removing an
 * exercise, or a whole session, deletes those rows in the database without
 * Eloquent ever hearing about it. Set::deleted never fired, so the volume of
 * everything ever deleted stayed in both counters permanently.
 *
 * The hooks on WorkoutLine and Workout stop the drift from here on. They cannot
 * undo what has already accumulated, and the number is on the user's dashboard,
 * so it is recomputed once from the only source that was never wrong: the sets
 * themselves.
 *
 * Idempotent by construction — it assigns a computed total rather than adjusting
 * one — so it is safe to run again if anything is ever in doubt.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // Workouts first: the user total is then just the sum of these.
        DB::statement(<<<'SQL'
            UPDATE workouts w
            SET w.workout_volume = COALESCE((
                SELECT SUM(COALESCE(s.weight, 0) * COALESCE(s.reps, 0))
                FROM sets s
                INNER JOIN workout_lines wl ON wl.id = s.workout_line_id
                WHERE wl.workout_id = w.id
            ), 0)
        SQL);

        DB::statement(<<<'SQL'
            UPDATE users u
            SET u.total_volume = COALESCE((
                SELECT SUM(w.workout_volume) FROM workouts w WHERE w.user_id = u.id
            ), 0)
        SQL);
    }

    /**
     * Deliberately empty. Down would mean restoring numbers that were wrong,
     * and nothing recorded what they were.
     */
    public function down(): void
    {
    }
};
