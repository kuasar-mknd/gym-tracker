<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoalType;
use App\Models\Goal;
use App\Models\User;

/**
 * Service for managing user goals and tracking progress.
 *
 * This service is responsible for synchronizing goal progress based on user activity
 * (workouts, measurements) and determining if a goal has been achieved.
 * It handles different types of goals: weight (strength), frequency, volume, and body measurements.
 */
final class GoalService
{
    /**
     * Synchronize all active goals for a user.
     *
     * Iterates through all the user's incomplete goals and triggers a progress update
     * for each one. This is typically called after a workout is finished or a measurement is added.
     *
     * @param  User  $user  The user whose goals should be synchronized.
     */
    public function syncGoals(User $user): void
    {
        $goals = $user->goals()->whereNull('completed_at')->get();
        foreach ($goals as $goal) {
            $goal->setRelation('user', $user);
            $this->updateGoalProgress($goal);
        }

        $dirtyGoals = $goals->filter->isDirty();
        if ($dirtyGoals->isNotEmpty()) {
            $now = now();
            $data = $dirtyGoals->map(function ($goal) use ($now) {
                $attrs = $goal->getAttributes();
                $attrs['updated_at'] = $now;

                return $attrs;
            })->toArray();

            Goal::upsert(
                $data,
                ['id'],
                ['current_value', 'progress_pct', 'completed_at', 'updated_at']
            );
        }
    }

    /**
     * Update progress for a specific goal.
     *
     * Dispatches the update logic to the appropriate method based on the goal's type.
     * After updating the progress value, it checks if the goal has been completed.
     *
     * @param  Goal  $goal  The goal to update.
     */
    public function updateGoalProgress(Goal $goal): void
    {
        match ($goal->type) {
            GoalType::Weight => $this->updateWeightGoal($goal),
            GoalType::Frequency => $this->updateFrequencyGoal($goal),
            GoalType::Volume => $this->updateVolumeGoal($goal),
            GoalType::Measurement => $this->updateMeasurementGoal($goal),
        };

        $this->checkCompletion($goal);
        $this->updateProgressPercentage($goal);
    }

    /**
     * Calculate and update the progress percentage.
     */
    protected function updateProgressPercentage(Goal $goal): void
    {
        if ($goal->target_value === $goal->start_value) {
            $goal->progress_pct = $goal->current_value >= $goal->target_value ? 100.0 : 0.0;

            return;
        }

        $totalDiff = abs($goal->target_value - $goal->start_value);
        $currentDiff = abs($goal->current_value - $goal->start_value);

        if ($totalDiff === 0.0) {
            $goal->progress_pct = 0.0;

            return;
        }

        $progress = $currentDiff / $totalDiff * 100;
        $goal->progress_pct = min(max($progress, 0), 100);
    }

    /**
     * Update progress for a weight (strength) goal.
     *
     * Finds the maximum weight lifted for the associated exercise across all user workouts.
     *
     * @param  Goal  $goal  The weight goal to update.
     */
    protected function updateWeightGoal(Goal $goal): void
    {
        if (! $goal->exercise_id) {
            return;
        }

        $maxWeight = $goal->user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workout_lines.exercise_id', $goal->exercise_id)
            ->max('sets.weight');

        if ($maxWeight && is_numeric($maxWeight)) {
            $goal->current_value = (float) $maxWeight;
        }
    }

    /**
     * Update progress for a frequency goal.
     *
     * Counts the total number of workouts the user has completed.
     *
     * @param  Goal  $goal  The frequency goal to update.
     */
    protected function updateFrequencyGoal(Goal $goal): void
    {
        // ⚡ Bolt Optimization: Use loadCount with null coalescing to cache the workouts count on the User model natively.
        // Impact: Reduces queries from N to 1 when a user has multiple frequency goals.
        $goal->user->workouts_count ??= $goal->user->workouts()->count();

        $goal->current_value = $goal->user->workouts_count;
    }

    /**
     * Update progress for a volume goal.
     *
     * Finds the maximum volume (weight * reps) achieved in a single workout
     * for the associated exercise.
     *
     * @param  Goal  $goal  The volume goal to update.
     */
    protected function updateVolumeGoal(Goal $goal): void
    {
        if (! $goal->exercise_id) {
            return;
        }

        // ⚡ Bolt Optimization: Calculate max volume directly in SQL instead of loading into PHP memory.
        // Impact: Reduces memory usage and improves performance for users with many workouts.
        $maxVolume = \App\Models\Workout::query()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $goal->user_id)
            ->where('workout_lines.exercise_id', $goal->exercise_id)
            ->selectRaw('SUM(sets.weight * sets.reps) as total_volume')
            ->groupBy('workouts.id')
            ->orderByDesc('total_volume')
            ->limit(1)
            ->value('total_volume');

        if ($maxVolume !== null && is_numeric($maxVolume)) {
            $goal->current_value = (float) $maxVolume;
        }
    }

    /**
     * Update progress for a body measurement goal.
     *
     * Retrieves the most recent recorded value for the specified measurement type.
     *
     * @param  Goal  $goal  The measurement goal to update.
     */
    protected function updateMeasurementGoal(Goal $goal): void
    {
        if (! $goal->measurement_type) {
            return;
        }

        $latestValue = $goal->user->bodyMeasurements()
            ->latest('measured_at')
            ->value($goal->measurement_type === 'weight' ? 'weight' : $goal->measurement_type);

        if ($latestValue && is_numeric($latestValue)) {
            $goal->current_value = (float) $latestValue;
        }
    }

    /**
     * Check if a goal has been completed.
     *
     * Compares the current value against the target value.
     * Handles both "higher is better" (strength, frequency) and "lower is better"
     * (e.g., weight loss) scenarios.
     * Updates the `completed_at` timestamp if the condition is met.
     *
     * @param  Goal  $goal  The goal to check.
     */
    protected function checkCompletion(Goal $goal): void
    {
        $isCompleted = $this->isGoalCriteriaMet($goal);

        if ($isCompleted && ! $goal->completed_at) {
            $goal->completed_at = now();

            return;
        }

        if (! $isCompleted && $goal->completed_at) {
            $goal->completed_at = null;
        }
    }

    /**
     * Determine if the goal's target criteria has been met.
     */
    protected function isGoalCriteriaMet(Goal $goal): bool
    {
        // Handle "lower is better" for specific measurements (e.g., body weight loss)
        if ($goal->type === GoalType::Measurement && $goal->target_value < $goal->start_value) {
            return $goal->current_value <= $goal->target_value && $goal->current_value > 0;
        }

        // For most goals, higher is better
        return $goal->current_value >= $goal->target_value;
    }
}
