<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Collection;
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
     * Includes optimization to avoid N+1 queries by pre-calculating metrics.
     *
     * @param  User  $user  The user to synchronize achievements for.
     */
    public function syncAchievements(User $user): void
    {
        // 1. Get IDs of already unlocked achievements
        $unlockedAchievementIds = $user->achievements()->pluck('achievements.id')->toArray();

        // 2. Get only locked achievements
        $lockedAchievements = Achievement::whereNotIn('id', $unlockedAchievementIds)->get();

        if ($lockedAchievements->isEmpty()) {
            return;
        }

        // 3. Pre-calculate metrics based on what types are present in locked achievements
        $metrics = $this->preCalculateMetrics($user, $lockedAchievements);

        foreach ($lockedAchievements as $achievement) {
            $isUnlocked = match ($achievement->type) {
                'count' => ($metrics['count'] ?? 0) >= $achievement->threshold,
                'weight_record' => ($metrics['max_weight'] ?? 0) >= $achievement->threshold,
                'volume_total' => ($metrics['total_volume'] ?? 0) >= $achievement->threshold,
                'streak' => ($metrics['max_streak'] ?? 0) >= $achievement->threshold,
                default => false,
            };

            if ($isUnlocked) {
                $user->achievements()->attach($achievement->id, [
                    'achieved_at' => now(),
                ]);

                $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
            }
        }
    }

    /**
     * Pre-calculate metrics for the given set of achievements to avoid N+1 queries.
     *
     * @return array<string, mixed>
     */
    private function preCalculateMetrics(User $user, Collection $achievements): array
    {
        $types = $achievements->pluck('type')->unique();
        $metrics = [];

        if ($types->contains('count')) {
            $metrics['count'] = $user->workouts()->count();
        }

        if ($types->contains('weight_record')) {
            $metrics['max_weight'] = $user->workouts()
                ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                ->max('sets.weight') ?? 0;
        }

        if ($types->contains('volume_total')) {
            $metrics['total_volume'] = $user->workouts()
                ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
                ->sum(DB::raw('sets.weight * sets.reps'));
        }

        if ($types->contains('streak')) {
            // Find the maximum threshold among streak achievements to determine lookback
            $maxStreakThreshold = $achievements->where('type', 'streak')->max('threshold');
            $metrics['max_streak'] = $this->calculateStreakForThreshold($user, (int) $maxStreakThreshold);
        }

        return $metrics;
    }

    /**
     * Calculate streak logic reused for pre-calculation.
     */
    private function calculateStreakForThreshold(User $user, int $threshold): int
    {
        $workoutDates = $this->getUniqueWorkoutDates($user, $threshold);

        if (empty($workoutDates)) {
            return 0;
        }

        return $this->calculateMaxStreak($workoutDates);
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

        // If there is only 1 date, streak is 1
        if ($count === 1) {
            return 1;
        }

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
