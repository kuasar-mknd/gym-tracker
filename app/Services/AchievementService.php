<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     */
    public function syncAchievements(User $user): void
    {
        $achievements = Achievement::all();

        foreach ($achievements as $achievement) {
            // Skip if already unlocked
            if ($user->achievements()->where('achievement_id', $achievement->id)->exists()) {
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

    protected function checkWeightRecord(User $user, float $threshold): bool
    {
        return $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('sets.weight', '>=', $threshold)
            ->exists();
    }

    protected function checkTotalVolume(User $user, float $threshold): bool
    {
        $totalVolume = $user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->sum(DB::raw('sets.weight * sets.reps'));

        return $totalVolume >= $threshold;
    }

    protected function checkStreak(User $user, float $threshold): bool
    {
        // Fetch all workout dates, formatted as Y-m-d
        $workoutDates = $user->workouts()
            ->latest('started_at')
            ->pluck('started_at')
            ->map(fn ($date) => $date->format('Y-m-d'))
            ->unique()
            ->values()
            ->toArray();

        $days = (int) $threshold;

        if (count($workoutDates) < $days) {
            return false;
        }

        // Check if there is a window of X consecutive days
        $currentStreak = 1;
        $maxStreak = 1;

        for ($i = 0; $i < count($workoutDates) - 1; $i++) {
            $current = \Carbon\Carbon::parse($workoutDates[$i]);
            $next = \Carbon\Carbon::parse($workoutDates[$i + 1]);

            // Dates are ordered descending (latest first)
            // Dates are ordered descending (latest first)
            // So if current is Today, next should be Yesterday (diff = 1)
            // Use abs() or explicit check because diffInDays may return negative if order is reversed
            if (abs((int) $current->diffInDays($next, false)) === 1) {
                $currentStreak++;
            } else {
                $maxStreak = max($maxStreak, $currentStreak);
                $currentStreak = 1;
            }
        }
        $maxStreak = max($maxStreak, $currentStreak);

        return $maxStreak >= $threshold;
    }
}
