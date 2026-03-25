<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/**
 * Service responsible for calculating and updating user workout streaks.
 *
 * This service determines the user's current consecutive workout streak,
 * tracks their longest historical streak, and updates the `last_workout_at`
 * timestamp. It handles both manual workout entries and historical data updates.
 */
final class StreakService
{
    /**
     * Update user streak based on the latest workout.
     *
     * Resolves the effective workout date, compares it to the last recorded
     * workout date, and calculates the new streak. If the streak is broken,
     * it resets to 1. If consecutive, it increments. It also updates the
     * user's `last_workout_at` timestamp.
     *
     * @param  \App\Models\User  $user  The user whose streak is being updated.
     * @param  \App\Models\Workout|null  $workout  The newly completed or updated workout (optional).
     */
    public function updateStreak(User $user, ?Workout $workout = null): void
    {
        $user->refresh();

        $workoutDate = $this->resolveWorkoutDate($user, $workout);

        if (! $workoutDate) {
            return;
        }

        $lastRecordedDate = $this->getLastRecordedDate($user);

        if ($lastRecordedDate?->equalTo($workoutDate)) {
            $user->last_workout_at = $workout->started_at ?? $user->last_workout_at;
            $user->save();
            return;
        }

        $this->calculateNewStreak($user, $workoutDate, $lastRecordedDate);

        // Ensure we assign a Carbon instance or null, handling the mixed return of value()
        $latestStartedAt = $user->workouts()->latest('started_at')->value('started_at');
        $latestStartedAtCarbon = null;

        if ($latestStartedAt && is_scalar($latestStartedAt)) {
            $latestStartedAtCarbon = Carbon::parse((string) $latestStartedAt);
        }

        $user->last_workout_at = $workout->started_at ?? $latestStartedAtCarbon;
        $user->save();
    }

    /**
     * Get the user's last recorded workout date as a Carbon instance.
     *
     * @param  \App\Models\User  $user  The user model.
     * @return \Illuminate\Support\Carbon|null The start of the day of the last workout, or null if never recorded.
     */
    protected function getLastRecordedDate(User $user): ?Carbon
    {
        return $user->last_workout_at ? Carbon::parse($user->last_workout_at)->startOfDay() : null;
    }

    /**
     * Calculate and update the user's current and longest streak values.
     *
     * Compares the new workout date against the last recorded date to determine
     * if the streak should increment (consecutive days), reset to 1 (broken streak),
     * or remain the same (same day). It also updates the `longest_streak` if
     * the new current streak exceeds it.
     *
     * @param  \App\Models\User  $user  The user whose streak is being calculated.
     * @param  \Illuminate\Support\Carbon  $workoutDate  The resolved start-of-day date of the current workout.
     * @param  \Illuminate\Support\Carbon|null  $lastRecordedDate  The start-of-day date of the previously recorded workout.
     */
    protected function calculateNewStreak(User $user, Carbon $workoutDate, ?Carbon $lastRecordedDate): void
    {
        if (! $lastRecordedDate) {
            // First workout ever
            $user->current_streak = 1;
        } else {
            $diffInDays = (int) $lastRecordedDate->diffInDays($workoutDate);

            if ($diffInDays === 1) {
                // Consecutive day
                $user->current_streak++;
            } elseif ($diffInDays > 1) {
                // Streak broken
                $user->current_streak = 1;
            }
        }

        // Update longest streak if necessary
        if ($user->current_streak > $user->longest_streak) {
            $user->longest_streak = $user->current_streak;
        }
    }

    /**
     * Resolve the correct date of the workout to be processed.
     *
     * If a specific workout is provided, its `started_at` date is used.
     * Otherwise, it queries the database for the user's most recent workout.
     * The returned date is always normalized to the start of the day to ensure
     * accurate day-to-day streak calculations.
     *
     * @param  \App\Models\User  $user  The user model.
     * @param  \App\Models\Workout|null  $workout  The explicitly provided workout.
     * @return \Illuminate\Support\Carbon|null The resolved date normalized to start of day, or null if no workouts exist.
     */
    private function resolveWorkoutDate(User $user, ?Workout $workout): ?Carbon
    {
        $startedAt = $workout->started_at ?? $user->workouts()->latest('started_at')->value('started_at');

        if ($startedAt instanceof Carbon) {
            return $startedAt->copy()->startOfDay();
        }

        return $startedAt && is_scalar($startedAt) ? Carbon::parse((string) $startedAt)->startOfDay() : null;
    }
}
