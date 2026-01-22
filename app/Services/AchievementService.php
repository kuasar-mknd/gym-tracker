<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing user achievements.
 *
 * This service handles the evaluation and unlocking of achievements based on user activity.
 * It checks various criteria such as workout counts, weight records, total volume, and streaks.
 * It also handles the notification process when an achievement is unlocked.
 */
class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     *
     * Iterates through all available achievements and checks if the user has met the criteria
     * for any locked achievements. If criteria are met, the achievement is attached to the user
     * and a notification is sent.
     *
     * Performance:
     * - Pre-loads already unlocked achievement IDs to prevent N+1 queries during the loop.
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
     * Check if the user meets the criteria for a specific achievement.
     *
     * Delegates the check to a specific method based on the achievement type.
     *
     * @param  User  $user  The user to check.
     * @param  Achievement  $achievement  The achievement to evaluate.
     * @return bool True if the criteria are met, false otherwise.
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
     * Examines all sets performed by the user to find if any set's weight meets the requirement.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The weight threshold to reach (in kg).
     * @return bool True if a matching record exists.
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
     * Check if the user's total lifted volume across all workouts meets the threshold.
     *
     * Calculates the sum of (weight * reps) for all sets ever performed by the user.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The total volume threshold to reach.
     * @return bool True if the total volume is greater than or equal to the threshold.
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
     * Check if the user has achieved a workout streak of the specified length.
     *
     * Evaluates recent workout history to find consecutive days of activity.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The number of consecutive days required.
     * @return bool True if a streak of at least $threshold days is found.
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
     * Retrieve unique workout dates for the user within a relevant timeframe.
     *
     * Fetches workout dates starting from (threshold + 30) days ago to ensure
     * sufficient history is available to detect the streak.
     *
     * @param  User  $user  The user to retrieve dates for.
     * @param  int  $days  The target streak length (used to calculate the lookback period).
     * @return array<int, string> Array of unique dates (Y-m-d) sorted by most recent first.
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
     * Calculate the maximum streak of consecutive days from a list of dates.
     *
     * Iterates through the sorted list of dates (descending) and counts consecutive days.
     *
     * @param  array<int, string>  $dates  List of unique dates (Y-m-d).
     * @return int The maximum number of consecutive days found.
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
