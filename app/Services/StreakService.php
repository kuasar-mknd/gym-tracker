<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/**
 * Service for calculating and updating user workout streaks.
 *
 * This service determines if a user's workout streak should be incremented,
 * maintained, or reset based on the date of their last recorded workout.
 */
final class StreakService
{
    /**
     * Update user streak based on the latest workout.
     *
     * @param  User  $user  The user whose streak is being updated.
     * @param  Workout|null  $workout  The workout triggering the streak update. If null, the latest workout is resolved.
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
     * Get the last recorded workout date as Carbon.
     *
     * @param  User  $user  The user to get the last recorded date for.
     * @return \Illuminate\Support\Carbon|null The last recorded workout date, or null if never worked out.
     */
    protected function getLastRecordedDate(User $user): ?Carbon
    {
        return $user->last_workout_at ? Carbon::parse($user->last_workout_at)->startOfDay() : null;
    }

    /**
     * Calculate and update the user's streak.
     *
     * @param  User  $user  The user whose streak is being calculated.
     * @param  \Illuminate\Support\Carbon  $workoutDate  The date of the current workout being processed.
     * @param  \Illuminate\Support\Carbon|null  $lastRecordedDate  The date of the user's previously recorded workout.
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
     * Resolve the workout date to be processed.
     *
     * @param  User  $user  The user associated with the workout.
     * @param  Workout|null  $workout  The workout to resolve the date for.
     * @return \Illuminate\Support\Carbon|null The resolved workout date, or null if none found.
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
