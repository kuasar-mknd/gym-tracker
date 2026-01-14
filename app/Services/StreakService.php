<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

class StreakService
{
    /**
     * Update user streak based on the latest workout.
     */
    public function updateStreak(User $user): void
    {
        // Get the latest workout date
        $latestWorkout = $user->workouts()->latest('started_at')->first();

        if (! $latestWorkout) {
            return;
        }

        $workoutDate = Carbon::parse($latestWorkout->started_at)->startOfDay();
        $lastRecordedDate = $user->last_workout_at ? Carbon::parse($user->last_workout_at)->startOfDay() : null;

        // If this workout is on the same day as the last recorded one, do nothing (streak already updated for today)
        if ($lastRecordedDate && $workoutDate->equalTo($lastRecordedDate)) {
            return;
        }

        // Calculate expected next day (yesterday relative to workout date, or just "previous day")
        // But we are processing "this" workout.
        // If last recorded date was Yesterday relative to this workout, increment streak.
        // If last recorded date was older, reset streak to 1.
        // If no last recorded date, set streak to 1.

        if ($lastRecordedDate) {
            $diffInDays = $lastRecordedDate->diffInDays($workoutDate);

            if ($diffInDays == 1) {
                // Consecutive day
                $user->increment('current_streak');
            } elseif ($diffInDays > 1) {
                // Streak broken
                $user->current_streak = 1;
            }
            // If diffInDays == 0, blocked by check above.
        } else {
            // First workout ever
            $user->current_streak = 1;
        }

        // Update longest streak if necessary
        if ($user->current_streak > $user->longest_streak) {
            $user->longest_streak = $user->current_streak;
        }

        // Update last workout timestamp
        $user->last_workout_at = $latestWorkout->started_at;
        $user->save();
    }
}
