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
    private array $maxWeightsCache = [];
    private array $maxVolumesCache = [];
    private array $hasFetchedMeasurements = [];
    private array $latestMeasurementCache = [];
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
        $this->maxWeightsCache = [];
        $this->maxVolumesCache = [];
        $this->hasFetchedMeasurements = [];
        $this->latestMeasurementCache = [];

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

        $userId = $goal->user_id;

        if (! isset($this->maxWeightsCache[$userId])) {
            $this->maxWeightsCache[$userId] = $goal->user->workouts()
                ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                ->selectRaw('workout_lines.exercise_id, MAX(sets.weight) as max_weight')
                ->groupBy('workout_lines.exercise_id')
                ->toBase()
                ->pluck('max_weight', 'exercise_id')
                ->all();
        }

        $maxWeight = $this->maxWeightsCache[$userId][$goal->exercise_id] ?? null;

        if ($maxWeight !== null && is_numeric($maxWeight)) {
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
        // ⚡ Bolt Optimization: Cache the workouts count on the User model natively.
        // Impact: Reduces queries from N to 1 when a user has multiple frequency goals.
        /** @phpstan-ignore assign.propertyReadOnly */
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

        // ⚡ Bolt Optimization: Precalculate max volumes for all exercises in the service cache to avoid N+1.
        // Impact: Reduces memory usage and queries from N to 1 for users with many goals.
        $userId = $goal->user_id;

        if (! isset($this->maxVolumesCache[$userId])) {
            $this->maxVolumesCache[$userId] = \App\Models\Workout::query()
                ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                ->where('workouts.user_id', $userId)
                ->selectRaw('workout_lines.exercise_id, workouts.id, SUM(sets.weight * sets.reps) as total_volume')
                ->groupBy('workout_lines.exercise_id', 'workouts.id')
                ->toBase()
                ->get()
                ->groupBy('exercise_id')
                ->map(fn ($items) => $items->max('total_volume'))
                ->all();
        }

        $maxVolume = $this->maxVolumesCache[$userId][$goal->exercise_id] ?? null;

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

        $userId = $goal->user_id;

        if (! isset($this->hasFetchedMeasurements[$userId])) {
            $this->latestMeasurementCache[$userId] = $goal->user->bodyMeasurements()
                ->latest('measured_at')
                ->first();
            $this->hasFetchedMeasurements[$userId] = true;
        }

        if (!empty($this->latestMeasurementCache[$userId])) {
            $type = $goal->measurement_type === 'weight' ? 'weight' : $goal->measurement_type;
            $latestValue = $this->latestMeasurementCache[$userId]->{$type};

            if ($latestValue !== null && is_numeric($latestValue)) {
                $goal->current_value = (float) $latestValue;
            }
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
