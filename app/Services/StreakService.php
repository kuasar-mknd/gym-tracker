<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

final class StreakService
{
    /**
     * Update user streak based on the latest workout.
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
     */
    protected function getLastRecordedDate(User $user): ?Carbon
    {
        return $user->last_workout_at ? Carbon::parse($user->last_workout_at)->startOfDay() : null;
    }

    /**
     * Calculate and update the user's streak.
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
