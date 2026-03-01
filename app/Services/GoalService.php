<?php

declare(strict_types=1);

namespace App\Services;

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
            $this->updateGoalProgress($goal);
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
            'weight' => $this->updateWeightGoal($goal),
            'frequency' => $this->updateFrequencyGoal($goal),
            'volume' => $this->updateVolumeGoal($goal),
            'measurement' => $this->updateMeasurementGoal($goal),
            default => null,
        };

        $this->checkCompletion($goal);
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

        if ($maxWeight) {
            $goal->update(['current_value' => $maxWeight]);
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
        $count = $goal->user->workouts()->count();
        $goal->update(['current_value' => $count]);
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

        // Finds the maximum volume achieved in a single workout for the associated exercise
        $maxVolume = $goal->user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workout_lines.exercise_id', $goal->exercise_id)
            ->selectRaw('SUM(sets.weight * sets.reps) as workout_total_volume')
            ->groupBy('workouts.id')
            ->get()
            ->max('workout_total_volume');

        if ($maxVolume) {
            $goal->update(['current_value' => (float) (is_numeric($maxVolume) ? $maxVolume : 0)]);
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
            $goal->update(['current_value' => (float) $latestValue]);
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
            $goal->update(['completed_at' => now()]);

            return;
        }

        if (! $isCompleted && $goal->completed_at) {
            $goal->update(['completed_at' => null]);
        }
    }

    /**
     * Determine if the goal's target criteria has been met.
     */
    protected function isGoalCriteriaMet(Goal $goal): bool
    {
        // Handle "lower is better" for specific measurements (e.g., body weight loss)
        if ($goal->type === 'measurement' && $goal->target_value < $goal->start_value) {
            return $goal->current_value <= $goal->target_value && $goal->current_value > 0;
        }

        // For most goals, higher is better
        return $goal->current_value >= $goal->target_value;
    }
}
