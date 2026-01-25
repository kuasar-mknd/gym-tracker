<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing user achievements and unlocking logic.
 *
 * This service is responsible for checking if a user has met the criteria for various
 * achievements (e.g., workout counts, volume milestones, weight records, streaks)
 * and unlocking them if applicable. It handles the synchronization process
 * to ensure all eligible achievements are awarded.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     *
     * Iterates through all available achievements and checks if the user has unlocked them.
     * If an achievement is unlocked, it attaches it to the user and sends a notification.
     * Optimized to skip already unlocked achievements.
     *
     * @param  User  $user  The user to synchronize achievements for.
     */
    public function syncAchievements(User $user): void
    {
        $achievements = Achievement::all();

        // NITRO FIX: Pre-load unlocked IDs to avoid N+1 query in loop
        $unlockedAchievementIds = $user->achievements()->pluck('achievements.id')->toArray();

        foreach ($achievements as $achievement) {
            // Skip if already unlocked - memory check, no query
            if (in_array($achievement->id, $unlockedAchievementIds)) {
                continue;
            }

            if ($this->checkAchievement($user, $achievement)) {
                $user->achievements()->attach($achievement->id, [
                    'achieved_at' => now(),
                ]);

                $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
            }
        }
    }

    /**
     * Check if a specific achievement condition is met by the user.
     *
     * Delegates the check to specific methods based on the achievement type.
     *
     * @param  User  $user  The user to check.
     * @param  Achievement  $achievement  The achievement to validate.
     * @return bool True if the achievement criteria are met, false otherwise.
     */
    protected function checkAchievement(User $user, Achievement $achievement): bool
    {
        return match ($achievement->type) {
            'count' => $user->workouts()->count() >= $achievement->threshold,
            'weight_record' => $this->checkWeightRecord($user, $achievement->threshold),
            'volume_total' => $this->checkTotalVolume($user, $achievement->threshold),
            'streak' => $this->checkStreak($user, $achievement->threshold),
            default => false,
        };
    }

    /**
     * Check if the user has lifted a weight equal to or greater than the threshold.
     *
     * Scans all user's sets to find if any set meets the weight requirement.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The weight threshold to reach.
     * @return bool
     */
    protected function checkWeightRecord(User $user, float $threshold): bool
    {
        return $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('sets.weight', '>=', $threshold)
            ->exists();
    }

    /**
     * Check if the user's total lifted volume exceeds the threshold.
     *
     * Calculates the sum of (weight * reps) across all workouts.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The volume threshold to reach.
     * @return bool
     */
    protected function checkTotalVolume(User $user, float $threshold): bool
    {
        $totalVolume = $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
            ->sum(DB::raw('sets.weight * sets.reps'));

        return $totalVolume >= $threshold;
    }

    /**
     * Check if the user has a workout streak of at least X days.
     *
     * A streak is defined as consecutive days with at least one workout.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The number of consecutive days required.
     * @return bool
     */
    protected function checkStreak(User $user, float $threshold): bool
    {
        $workoutDates = $this->getUniqueWorkoutDates($user, (int) $threshold);

        if (count($workoutDates) < (int) $threshold) {
            return false;
        }

        return $this->calculateMaxStreak($workoutDates) >= (int) $threshold;
    }

    /**
     * Get a list of unique dates where the user performed a workout.
     *
     * Retrieves dates from the database, focusing on the recent period relevant to the streak check.
     *
     * @param  User  $user  The user to retrieve dates for.
     * @param  int  $days  The minimum number of days we are looking for (used to optimize the query window).
     * @return array<int, string> List of unique dates in 'Y-m-d' format.
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        /** @var \Illuminate\Support\Collection<int, string> $dates */
        $dates = $user->workouts()
            ->where('started_at', '>=', now()->subDays($days + 30))
            ->latest('started_at')
            ->pluck('started_at');

        /** @var array<int, string> $result */
        $result = $dates->map(fn (string $date): string => \Illuminate\Support\Carbon::parse($date)->format('Y-m-d'))
            ->unique()
            ->values()
            ->toArray();

        return $result;
    }

    /**
     * Calculate the maximum consecutive day streak from a list of dates.
     *
     * Iterates through sorted dates to find the longest sequence of consecutive days.
     *
     * @param  array<int, string>  $dates  List of sorted unique dates (Y-m-d).
     * @return int The maximum streak length found.
     */
    private function calculateMaxStreak(array $dates): int
    {
        $currentStreak = 1;
        $maxStreak = 1;
        $count = count($dates);

        for ($i = 0; $i < $count - 1; $i++) {
            $current = \Carbon\Carbon::parse($dates[$i]);
            $next = \Carbon\Carbon::parse($dates[$i + 1]);

            if (abs((int) $current->diffInDays($next, false)) === 1) {
                $currentStreak++;
            } else {
                $maxStreak = max($maxStreak, $currentStreak);
                $currentStreak = 1;
            }
        }

        return max($maxStreak, $currentStreak);
    }
}
