<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing user achievements.
 *
 * This service handles the synchronization and verification of achievements
 * based on user activity (workouts, volume, records, streaks).
 * It calculates eligibility for various achievement types and unlocks them if criteria are met.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     *
     * Iterates through all available achievements and checks if the user
     * has met the criteria to unlock them. If eligible, the achievement
     * is attached to the user and a notification is sent.
     *
     * Includes optimization to avoid N+1 queries by pre-loading unlocked achievements.
     *
     * @param  User  $user  The user to synchronize achievements for.
     */
    public function syncAchievements(User $user): void
    {
        $achievements = Achievement::all();

        // NITRO FIX: Pre-load unlocked IDs to avoid N+1 query in loop
        $unlockedAchievementIds = $user->achievements()->pluck('achievements.id')->toArray();

        $lockedAchievements = $achievements->reject(fn ($a) => in_array($a->id, $unlockedAchievementIds));

        if ($lockedAchievements->isEmpty()) {
            return;
        }

        $types = $lockedAchievements->pluck('type')->unique();
        $stats = [];

        if ($types->contains('count')) {
            $stats['count'] = $user->workouts()->count();
        }

        if ($types->contains('weight_record')) {
            $stats['weight_record'] = $user->workouts()
                ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                ->max('sets.weight') ?? 0;
        }

        if ($types->contains('volume_total')) {
            $stats['volume_total'] = (float) $user->workouts()
                ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                ->sum(DB::raw('sets.weight * sets.reps'));
        }

        if ($types->contains('streak')) {
            $maxStreakThreshold = (int) $lockedAchievements->where('type', 'streak')->max('threshold');
            $workoutDates = $this->getUniqueWorkoutDates($user, $maxStreakThreshold);
            $stats['streak'] = $this->calculateMaxStreak($workoutDates);
        }

        foreach ($lockedAchievements as $achievement) {
            $isEligible = match ($achievement->type) {
                'count' => ($stats['count'] ?? 0) >= $achievement->threshold,
                'weight_record' => ($stats['weight_record'] ?? 0) >= $achievement->threshold,
                'volume_total' => ($stats['volume_total'] ?? 0) >= $achievement->threshold,
                'streak' => ($stats['streak'] ?? 0) >= $achievement->threshold,
                default => false,
            };

            if ($isEligible) {
                $user->achievements()->attach($achievement->id, [
                    'achieved_at' => now(),
                ]);

                $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
            }
        }
    }

    /**
     * Check if a user meets the requirements for a specific achievement.
     *
     * Delegates the check to specific methods based on the achievement type:
     * - count: Total number of workouts.
     * - weight_record: Maximum weight lifted in a single set.
     * - volume_total: Cumulative volume lifted across all workouts.
     * - streak: Consecutive days of workouts.
     *
     * @param  User  $user  The user to check.
     * @param  Achievement  $achievement  The achievement to verify.
     * @return bool True if the user meets the criteria, false otherwise.
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
     * Check if the user has lifted a specific weight in any set.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The weight threshold to reach.
     * @return bool True if a set exists with weight >= threshold.
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
     * Check if the user's total lifetime volume meets a threshold.
     *
     * Calculates the sum of (weight * reps) for all sets in all workouts.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The volume threshold to reach.
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
     * Calculates the maximum number of consecutive days with at least one workout.
     *
     * @param  User  $user  The user to check.
     * @param  float  $threshold  The number of consecutive days required.
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
     * Get unique workout dates for the user within a lookback period.
     *
     * Retrieves dates where workouts were started, looking back
     * the threshold days + a buffer of 30 days.
     *
     * @param  User  $user  The user to retrieve dates for.
     * @param  int  $days  The base number of days to look back (usually the threshold).
     * @return array<int, string> List of unique dates in 'Y-m-d' format.
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        $dates = $user->workouts()
            ->where('started_at', '>=', now()->subDays($days + 30))
            ->latest('started_at')
            ->pluck('started_at');

        return $dates->map(function (mixed $date): string {
            if ($date instanceof \DateTimeInterface) {
                return $date->format('Y-m-d');
            }

            return \Illuminate\Support\Carbon::parse(is_string($date) ? $date : '')->format('Y-m-d');
        })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Calculate the maximum streak from a list of dates.
     *
     * Iterates through the sorted dates to find the longest sequence of
     * consecutive days.
     *
     * @param  array<int, string>  $dates  List of dates (Y-m-d).
     * @return int The maximum number of consecutive days.
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
