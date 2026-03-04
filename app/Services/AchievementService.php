<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Service for managing user achievements.
 *
 * This service handles the business logic for gamification. It evaluates
 * a user's progress against all available locked achievements and unlocks
 * them if the required criteria (e.g., total workouts, total volume, streaks) are met.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     *
     * Retrieves all achievements the user has not yet unlocked, pre-calculates
     * the necessary metrics based on the locked achievement types to avoid N+1
     * query issues, and checks each one to see if it should be unlocked.
     *
     * @param  User  $user  The user to synchronize achievements for.
     */
    public function syncAchievements(User $user): void
    {
        $unlockedIds = $user->achievements()->pluck('achievements.id')->toArray();
        $locked = Achievement::whereNotIn('id', $unlockedIds)->get();

        if ($locked->isEmpty()) {
            return;
        }

        $metrics = $this->preCalculateMetrics($user, $locked);

        foreach ($locked as $achievement) {
            $this->checkAndUnlock($user, $achievement, $metrics);
        }
    }

    /**
     * Check if a specific achievement's criteria are met and unlock it if so.
     *
     * Matches the achievement's type against the pre-calculated metrics.
     * If the metric meets or exceeds the threshold, the achievement is attached
     * to the user and a notification is dispatched.
     *
     * @param  User  $user  The user being evaluated.
     * @param  Achievement  $achievement  The specific achievement to check.
     * @param  array<string, int|float>  $metrics  The pre-calculated user metrics.
     */
    private function checkAndUnlock(User $user, Achievement $achievement, array $metrics): void
    {
        $isUnlocked = match ($achievement->type) {
            'count' => ($metrics['count'] ?? 0) >= $achievement->threshold,
            'weight_record' => ($metrics['max_weight'] ?? 0) >= $achievement->threshold,
            'volume_total' => ($metrics['total_volume'] ?? 0) >= $achievement->threshold,
            'streak' => ($metrics['max_streak'] ?? 0) >= $achievement->threshold,
            default => false,
        };

        if ($isUnlocked) {
            $user->achievements()->attach($achievement->id, ['achieved_at' => now()]);
            $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
        }
    }

    /**
     * Pre-calculate metrics required for the given set of locked achievements.
     *
     * Optimization method to query the database only for the types of metrics
     * (e.g., count, max weight, total volume, streaks) needed by the currently
     * locked achievements.
     *
     * @param  User  $user  The user to calculate metrics for.
     * @param  Collection<int, Achievement>  $achievements  The collection of locked achievements.
     * @return array<string, int|float> An associative array of calculated metric values.
     */
    private function preCalculateMetrics(User $user, Collection $achievements): array
    {
        $types = $achievements->pluck('type')->unique();
        $metrics = [];

        if ($types->contains('count')) {
            $metrics['count'] = $user->workouts()->count();
        }

        if ($types->contains('weight_record')) {
            $metrics['max_weight'] = $this->calculateMaxWeight($user);
        }

        if ($types->contains('volume_total')) {
            $metrics['total_volume'] = $this->calculateTotalVolume($user);
        }

        if ($types->contains('streak')) {
            $metrics['max_streak'] = $this->calculateStreakMetric($user, $achievements);
        }

        return $metrics;
    }

    /**
     * Calculate the maximum weight ever lifted by the user.
     *
     * @param  User  $user  The user.
     * @return float The maximum weight lifted, or 0.0 if none.
     */
    private function calculateMaxWeight(User $user): float
    {
        /** @var float|null $maxWeight */
        $maxWeight = $user->personalRecords()
            ->where('type', 'max_weight')
            ->max('value');

        return (float) ($maxWeight ?? 0.0);
    }

    /**
     * Retrieve the user's total accumulated workout volume.
     *
     * @param  User  $user  The user.
     * @return float The total volume.
     */
    private function calculateTotalVolume(User $user): float
    {
        return (float) $user->total_volume;
    }

    /**
     * Calculate the user's longest workout streak relative to the required threshold.
     *
     * Determines the maximum threshold needed among all locked streak achievements
     * to limit the query scope when fetching dates.
     *
     * @param  User  $user  The user.
     * @param  Collection<int, Achievement>  $achievements  The locked achievements to find the threshold from.
     * @return int The calculated max streak.
     */
    private function calculateStreakMetric(User $user, Collection $achievements): int
    {
        /** @var float|int|null $maxStreakThreshold */
        $maxStreakThreshold = $achievements->where('type', 'streak')->max('threshold');

        if ($maxStreakThreshold === null) {
            return 0;
        }

        return $this->calculateStreakForThreshold($user, (int) $maxStreakThreshold);
    }

    /**
     * Calculate the maximum workout streak for a given threshold period.
     *
     * @param  User  $user  The user.
     * @param  int  $threshold  The maximum streak threshold needed (in days).
     * @return int The calculated maximum streak length in days.
     */
    private function calculateStreakForThreshold(User $user, int $threshold): int
    {
        $workoutDates = $this->getUniqueWorkoutDates($user, $threshold);

        if ($workoutDates === []) {
            return 0;
        }

        return $this->calculateMaxStreak($workoutDates);
    }

    /**
     * Get a list of unique dates on which the user recorded a workout.
     *
     * Fetches dates looking back a specific number of days (plus a buffer)
     * and normalizes them to 'Y-m-d' strings for easy streak comparison.
     *
     * @param  User  $user  The user.
     * @param  int  $days  The number of days to look back for streak calculation.
     * @return array<int, string> An array of unique 'Y-m-d' date strings.
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        $dates = $user->workouts()
            ->where('started_at', '>=', now()->subDays($days + 30))
            ->latest('started_at')
            ->pluck('started_at');

        /** @var Collection<int, string> $mapped */
        $mapped = $dates->map(function ($date): string {
            if ($date instanceof \DateTimeInterface) {
                return $date->format('Y-m-d');
            }

            return \Illuminate\Support\Carbon::parse(is_string($date) ? $date : '')->format('Y-m-d');
        });

        return $mapped->unique()->values()->all();
    }

    /**
     * Calculate the longest consecutive day streak from a list of dates.
     *
     * Iterates through the sorted (descending by nature of `latest` query) array
     * of workout dates to find the maximum number of consecutive days worked out.
     *
     * @param  array<int, string>  $dates  An array of unique 'Y-m-d' dates.
     * @return int The maximum streak count.
     */
    private function calculateMaxStreak(array $dates): int
    {
        $currentStreak = 1;
        $maxStreak = 1;
        $count = count($dates);

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
