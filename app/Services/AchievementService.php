<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        // 1. Pre-calculate global stats to avoid N+1 queries
        $workoutsCount = $user->workouts()->count();

        $maxWeight = (float) $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->max('sets.weight') ?? 0.0;

        $totalVolume = (float) $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->sum(DB::raw('sets.weight * sets.reps'));

        // Pre-calculate streak (looking back 400 days to cover yearly streaks + buffer)
        // We limit to 400 days to keep memory usage low while covering the most common streak achievements (up to 1 year).
        $workoutDates = $this->getUniqueWorkoutDates($user, 400);
        $maxStreak = count($workoutDates) > 0 ? $this->calculateMaxStreak($workoutDates) : 0;

        $stats = [
            'count' => $workoutsCount,
            'max_weight' => $maxWeight,
            'total_volume' => $totalVolume,
            'max_streak' => $maxStreak,
        ];

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
     * @param array{count: int, max_weight: float, total_volume: float, max_streak: int} $stats
     */
    protected function checkAchievement(User $user, Achievement $achievement, array $stats): bool
    {
        return match ($achievement->type) {
            'count' => $stats['count'] >= $achievement->threshold,
            'weight_record' => $stats['max_weight'] >= $achievement->threshold,
            'volume_total' => $stats['total_volume'] >= $achievement->threshold,
            'streak' => $stats['max_streak'] >= $achievement->threshold,
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    private function getUniqueWorkoutDates(User $user, int $days): array
    {
        /** @var \Illuminate\Support\Collection<int, string> $dates */
        $dates = $user->workouts()
            ->where('started_at', '>=', now()->subDays($days))
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
