<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\User;

class GoalService
{
    /**
     * Synchronize all active goals for a user.
     */
    public function syncGoals(User $user): void
    {
        $user->goals()->whereNull('completed_at')->each(function (Goal $goal) {
            $this->updateGoalProgress($goal);
        });
    }

    /**
     * Update progress for a specific goal.
     */
    public function updateGoalProgress(Goal $goal): void
    {
        switch ($goal->type) {
            case 'weight':
                $this->updateWeightGoal($goal);
                break;
            case 'frequency':
                $this->updateFrequencyGoal($goal);
                break;
            case 'volume':
                $this->updateVolumeGoal($goal);
                break;
            case 'measurement':
                $this->updateMeasurementGoal($goal);
                break;
        }

        $this->checkCompletion($goal);
    }

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

    protected function updateFrequencyGoal(Goal $goal): void
    {
        $count = $goal->user->workouts()->count();
        $goal->update(['current_value' => $count]);
    }

    protected function updateVolumeGoal(Goal $goal): void
    {
        if (! $goal->exercise_id) {
            return;
        }

        $maxVolume = $goal->user->workouts()
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workout_lines.exercise_id', $goal->exercise_id)
            ->selectRaw('SUM(sets.weight * sets.reps) as total_volume')
            ->groupBy('workouts.id')
            ->max('total_volume');

        if ($maxVolume) {
            $goal->update(['current_value' => $maxVolume]);
        }
    }

    protected function updateMeasurementGoal(Goal $goal): void
    {
        if (! $goal->measurement_type) {
            return;
        }

        $latestValue = $goal->user->bodyMeasurements()
            ->latest('measured_at')
            ->value($goal->measurement_type === 'weight' ? 'weight' : $goal->measurement_type);

        if ($latestValue) {
            $goal->update(['current_value' => (float) $latestValue]);
        }
    }

    protected function checkCompletion(Goal $goal): void
    {
        $isCompleted = false;

        // For most goals, higher is better
        if ($goal->current_value >= $goal->target_value) {
            $isCompleted = true;
        }

        // Handle "lower is better" for specific measurements (e.g., body weight loss)
        if ($goal->type === 'measurement' && $goal->target_value < $goal->start_value) {
            if ($goal->current_value <= $goal->target_value && $goal->current_value > 0) {
                $isCompleted = true;
            } else {
                $isCompleted = false;
            }
        }

        if ($isCompleted && ! $goal->completed_at) {
            $goal->update(['completed_at' => now()]);
        } elseif (! $isCompleted && $goal->completed_at) {
            $goal->update(['completed_at' => null]);
        }
    }
}
