<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing user achievements.
 *
 * This service handles the logic for checking and unlocking achievements
 * based on user activity such as workouts, weight records, volume totals, and streaks.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     *
     * Iterates through all available achievements and checks if the user has met the criteria
     * for any locked achievements. Unlocked achievements are recorded in the database,
     * and a notification is sent to the user.
     *
     * Optimization: Pre-loads already unlocked achievement IDs to avoid N+1 queries.
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
     * Check if a specific achievement criteria is met.
     *
     * Dispatches the check to the appropriate method based on the achievement type.
     *
     * @param  User  $user  The user to check.
     * @param  Achievement  $achievement  The achievement to validate.
     * @return bool True if the achievement criteria is met, false otherwise.
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
     * Check if the user has lifted a specific weight.
     *
     * Verifies if any set in any workout meets or exceeds the weight threshold.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The weight threshold to meet.
     * @return bool True if the user has lifted >= threshold.
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
     * Check if the user has reached a total volume threshold.
     *
     * Calculates the total volume (weight * reps) across all sets and checks if it exceeds the threshold.
     * Note: This implementation sums volume across ALL workouts, which might be intended for "lifetime volume" achievements.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The volume threshold.
     * @return bool True if total volume >= threshold.
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
     * Check if the user has maintained a workout streak.
     *
     * Calculates the maximum consecutive days the user has worked out.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The streak length (days) required.
     * @return bool True if max streak >= threshold.
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
     * Get unique workout dates for the user.
     *
     * Retrieves workout dates looking back (threshold + 30) days to ensure we catch enough history
     * for the streak calculation.
     *
     * @param  User  $user  The user to retrieve dates for.
     * @param  int  $days  The number of days related to the streak threshold.
     * @return array<int, string> Array of unique dates (Y-m-d).
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
     * Calculate the maximum streak from a list of dates.
     *
     * Iterates through the sorted dates to find the longest sequence of consecutive days.
     *
     * @param  array<int, string>  $dates  List of unique dates (Y-m-d).
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
