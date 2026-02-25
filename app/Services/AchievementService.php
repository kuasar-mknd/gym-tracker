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
        $unlockedAchievementIds = $user->achievements()->pluck('achievements.id')->toArray();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Achievement> $lockedAchievements */
        $lockedAchievements = Achievement::whereNotIn('id', $unlockedAchievementIds)->get();

        if ($lockedAchievements->isEmpty()) {
            return;
        }

        $metrics = $this->preCalculateMetrics($user, $lockedAchievements);

        foreach ($lockedAchievements as $achievement) {
            $this->checkAndUnlock($user, $achievement, $metrics);
        }
    }

    /**
     * @param  array<string, int|float>  $metrics
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
     * @param  Collection<int, Achievement>  $achievements
     * @return array<string, int|float>
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

    private function calculateMaxWeight(User $user): float
    {
        /** @var float|null $maxWeight */
        $maxWeight = $user->personalRecords()
            ->where('type', 'max_weight')
            ->max('value');

        return (float) ($maxWeight ?? 0.0);
    }

    private function calculateTotalVolume(User $user): float
    {
        return (float) $user->total_volume;
    }

    /**
     * @param  Collection<int, Achievement>  $achievements
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

    private function calculateStreakForThreshold(User $user, int $threshold): int
    {
        $workoutDates = $this->getUniqueWorkoutDates($user, $threshold);

        if (count($workoutDates) === 0) {
            return 0;
        }

        return $this->calculateMaxStreak($workoutDates);
    }

    /**
     * @return array<int, string>
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        $dates = $user->workouts()
            ->where('started_at', '>=', now()->subDays($days + 30))
            ->latest('started_at')
            ->pluck('started_at');

        return $dates->map(function ($date): string {
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
     * @param  array<int, string>  $dates
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
