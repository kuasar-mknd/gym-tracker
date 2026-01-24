<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing user achievements and gamification logic.
 *
 * This service handles the synchronization and unlocking of achievements based on
 * various criteria such as workout counts, volume thresholds, weight records, and streaks.
 * It is optimized to prevent N+1 queries during bulk synchronization.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     *
     * Iterates through all available achievements and checks if the user
     * has unlocked them. If unlocked, it attaches the achievement to the user
     * and sends a notification.
     *
     * Optimization: Pre-loads the user's already unlocked achievements to avoid
     * querying the pivot table for every check.
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
     * Check if a specific achievement condition is met.
     *
     * Delegates the check to specific methods based on the achievement type.
     *
     * @param  User  $user  The user to check.
     * @param  Achievement  $achievement  The achievement to validate.
     * @return bool True if the achievement conditions are met.
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
     * Check if the user has lifted a weight >= threshold in any workout.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The weight threshold in kg.
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
     * Check if the user's total volume (all time) meets the threshold.
     *
     * Calculates volume as sum(weight * reps) across all sets.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The volume threshold.
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
     * Check if the user has a workout streak >= threshold.
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
     * Retrieve unique workout dates for the user within a lookback period.
     *
     * The lookback period is calculated as the threshold + 30 days buffer
     * to ensure we have enough history to detect the streak.
     *
     * @param  User  $user  The user to retrieve dates for.
     * @param  int  $days  The minimum number of days required (used for optimization).
     * @return array<int, string> List of unique dates in Y-m-d format.
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
     * Iterates through the sorted dates and counts consecutive days.
     *
     * @param  array<int, string>  $dates  List of dates (Y-m-d).
     * @return int The maximum streak found.
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
