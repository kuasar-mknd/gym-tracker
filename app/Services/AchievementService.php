<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Service for managing user achievements.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     */
    public function syncAchievements(User $user): void
    {
        $unlockedIds = $user->achievements()->pluck('achievements.id')->toArray();
        // ⚡ Bolt Optimization: Use cached all() collection and filter in-memory
        // Impact: Eliminates a database query during the frequently called sync operation
        $locked = Achievement::getCachedAll()->whereNotIn('id', $unlockedIds)->values();

        if ($locked->isEmpty()) {
            return;
        }

        $metrics = $this->preCalculateMetrics($user, $locked);
        $toUnlock = [];

        foreach ($locked as $achievement) {
            if ($this->isAchievementMet($achievement, $metrics)) {
                $toUnlock[] = $achievement;
            }
        }

        if (count($toUnlock) > 0) {
            $now = now();
            $attachData = [];
            foreach ($toUnlock as $achievement) {
                $attachData[$achievement->id] = ['achieved_at' => $now];
            }

            // ⚡ Bolt Optimization: Use a single attach() call to perform a batch insert
            // into the pivot table, reducing database roundtrips from O(N) to O(1).
            $user->achievements()->attach($attachData);

            // Notify user for each unlocked achievement
            foreach ($toUnlock as $achievement) {
                $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
            }
        }
    }

    /**
     * Determine if the criteria for an achievement is met.
     *
     * @param  Achievement  $achievement  The achievement to check.
     * @param  array<string, int|float>  $metrics  The pre-calculated metrics.
     * @return bool True if met, false otherwise.
     */
    private function isAchievementMet(Achievement $achievement, array $metrics): bool
    {
        return match ($achievement->type) {
            'count' => ($metrics['count'] ?? 0) >= $achievement->threshold,
            'weight_record' => ($metrics['max_weight'] ?? 0) >= $achievement->threshold,
            'volume_total' => ($metrics['total_volume'] ?? 0) >= $achievement->threshold,
            'streak' => ($metrics['max_streak'] ?? 0) >= $achievement->threshold,
            default => false,
        };
    }

    /**
     * Pre-calculate metrics required for checking multiple achievements efficiently.
     *
     * @param  User  $user  The user to calculate metrics for.
     * @param  Collection<int, Achievement>  $achievements  The achievements that need metrics.
     * @return array<string, int|float> A dictionary of pre-calculated metrics.
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
     * Calculate the maximum weight lifted by a user.
     *
     * @param  User  $user  The user to calculate the max weight for.
     * @return float The maximum weight lifted.
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
     * Calculate the total volume lifted by a user.
     *
     * @param  User  $user  The user to calculate the total volume for.
     * @return float The total volume lifted.
     */
    private function calculateTotalVolume(User $user): float
    {
        return (float) $user->total_volume;
    }

    /**
     * Calculate the relevant streak metric based on the highest streak threshold in the achievements collection.
     *
     * @param  User  $user  The user to calculate the streak for.
     * @param  Collection<int, Achievement>  $achievements  The achievements to determine the maximum threshold from.
     * @return int The max streak up to the required threshold, or 0 if none exist.
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
     * Calculate the user's max streak over a given threshold of days.
     *
     * @param  User  $user  The user to calculate the streak for.
     * @param  int  $threshold  The threshold of days to evaluate the streak over.
     * @return int The max streak for the threshold.
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
     * Get an array of unique dates the user worked out within a specific timeframe.
     *
     * @param  User  $user  The user to get the dates for.
     * @param  int  $days  The number of days to look back for workouts.
     * @return array<int, string> An array of unique 'Y-m-d' date strings.
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        // ⚡ Bolt Optimization: Use database-level DISTINCT and DATE() to reduce data volume
        // and eliminate PHP-side unique filtering. This significantly reduces memory usage
        // and execution time for large datasets.
        return $user->workouts()
            ->toBase()
            ->where('started_at', '>=', now()->subDays($days + 30))
            ->selectRaw('DISTINCT DATE(started_at) as date')
            ->orderByDesc('date')
            ->pluck('date')
            ->all();
    }

    /**
     * Calculate the maximum consecutive streak from an array of consecutive dates.
     *
     * @param  array<int, string>  $dates  The dates to calculate the streak from.
     * @return int The max consecutive streak found in the dates array.
     */
    private function calculateMaxStreak(array $dates): int
    {
        $currentStreak = 1;
        $maxStreak = 1;
        $count = count($dates);

        if ($count <= 1) {
            return $count;
        }

        // ⚡ Bolt Optimization: Use native PHP timestamp math instead of Carbon objects
        // to eliminate O(N) object instantiation overhead inside the loop.
        $current = strtotime($dates[0]);

        if ($current === false) {
            return 1;
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $next = strtotime($dates[$i + 1]);

            if ($next === false) {
                continue;
            }

            // 86400 seconds in a day. Round to avoid timezone shift issues during daylight saving.
            if (abs((int) round(($next - $current) / 86400)) === 1) {
                $currentStreak++;
            } else {
                $maxStreak = max($maxStreak, $currentStreak);
                $currentStreak = 1;
            }
            $current = $next;
        }

        return max($maxStreak, $currentStreak);
    }
}
