<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     */
    public function syncAchievements(User $user): void
    {
        $achievements = Achievement::all();

        // NITRO FIX: Pre-load unlocked IDs to avoid N+1 query in loop
        $unlockedAchievementIds = $user->achievements()->pluck('achievements.id')->toArray();

        // NITRO FIX: Pre-load user stats to avoid N+1 query in loop
        $stats = $this->preloadUserStats($user);

        foreach ($achievements as $achievement) {
            // Skip if already unlocked - memory check, no query
            if (in_array($achievement->id, $unlockedAchievementIds)) {
                continue;
            }

            if ($this->checkAchievement($user, $achievement, $stats)) {
                $user->achievements()->attach($achievement->id, [
                    'achieved_at' => now(),
                ]);

                $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
            }
        }
    }

    /**
     * Preload all necessary user statistics for achievement checks.
     *
     * @return array{
     *   count: int,
     *   max_weight: float,
     *   total_volume: float,
     *   workout_dates: array<int, string>
     * }
     */
    protected function preloadUserStats(User $user): array
    {
        // 1. Workout Count
        $workoutCount = $user->workouts()->count();

        // 2. Max Weight (join sets)
        /** @var float|int|null $maxWeight */
        $maxWeight = $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->max('sets.weight');

        // 3. Total Volume
        /** @var float|int $totalVolume */
        $totalVolume = $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->sum(DB::raw('sets.weight * sets.reps'));

        // 4. Workout Dates (for streak) - Fetch last 365 days to be safe for most streaks
        // If an achievement requires > 365 days, this logic would need adjustment,
        // but for performance 365 is a reasonable buffer.
        /** @var array<int, string> $workoutDates */
        $workoutDates = $user->workouts()
            ->where('started_at', '>=', now()->subDays(365))
            ->latest('started_at')
            ->pluck('started_at')
            ->map(function (mixed $date): string {
                /** @var string|null $d */
                $d = $date;
                return Carbon::parse($d ?? now())->format('Y-m-d');
            })
            ->unique()
            ->values()
            ->toArray();

        return [
            'count' => $workoutCount,
            'max_weight' => (float) ($maxWeight ?? 0),
            'total_volume' => (float) $totalVolume,
            'workout_dates' => $workoutDates,
        ];
    }

    /**
     * @param array{
     *   count: int,
     *   max_weight: float,
     *   total_volume: float,
     *   workout_dates: array<int, string>
     * } $stats
     */
    protected function checkAchievement(User $user, Achievement $achievement, array $stats): bool
    {
        return match ($achievement->type) {
            'count' => $stats['count'] >= $achievement->threshold,
            'weight_record' => $stats['max_weight'] >= $achievement->threshold,
            'volume_total' => $stats['total_volume'] >= $achievement->threshold,
            'streak' => $this->checkStreakInMemory($stats['workout_dates'], $achievement->threshold),
            default => false,
        };
    }

    protected function checkWeightRecord(User $user, float $threshold): bool
    {
        // Deprecated in favor of preloaded stats, but kept for direct calls if any
        return $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('sets.weight', '>=', $threshold)
            ->exists();
    }

    protected function checkTotalVolume(User $user, float $threshold): bool
    {
         // Deprecated in favor of preloaded stats
        $totalVolume = $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
            ->sum(DB::raw('sets.weight * sets.reps'));

        return $totalVolume >= $threshold;
    }

    protected function checkStreak(User $user, float $threshold): bool
    {
         // Deprecated in favor of preloaded stats
        $workoutDates = $this->getUniqueWorkoutDates($user, (int) $threshold);

        if (count($workoutDates) < (int) $threshold) {
            return false;
        }

        return $this->calculateMaxStreak($workoutDates) >= (int) $threshold;
    }

    /**
     * @param array<int, string> $workoutDates
     */
    protected function checkStreakInMemory(array $workoutDates, float $threshold): bool
    {
        if (count($workoutDates) < (int) $threshold) {
            return false;
        }

        return $this->calculateMaxStreak($workoutDates) >= (int) $threshold;
    }

    /**
     * @return array<int, string>
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        /** @var \Illuminate\Support\Collection<int, string> $dates */
        $dates = $user->workouts()
            ->where('started_at', '>=', now()->subDays($days + 30))
            ->latest('started_at')
            ->pluck('started_at');

        /** @var array<int, string> $result */
        $result = $dates->map(fn (string $date): string => Carbon::parse($date)->format('Y-m-d'))
            ->unique()
            ->values()
            ->toArray();

        return $result;
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function calculateMaxStreak(array $dates): int
    {
        $currentStreak = 1;
        $maxStreak = 1;
        $count = count($dates);

        if ($count === 0) {
            return 0;
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $current = Carbon::parse($dates[$i]);
            $next = Carbon::parse($dates[$i + 1]);

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
