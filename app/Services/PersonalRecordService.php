<?php

namespace App\Services;

use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;

class PersonalRecordService
{
    /**
     * Check and record PRs for a specific set.
     */
    public function syncSetPRs(Set $set): void
    {
        $user = $set->workoutLine->workout->user;
        $exerciseId = $set->workoutLine->exercise_id;
        $workoutId = $set->workoutLine->workout_id;

        // 1. Max Weight PR
        $this->updateMaxWeightPR($user, $exerciseId, $set, $workoutId);

        // 2. Max 1RM PR (Estimated)
        $this->updateMax1RMPR($user, $exerciseId, $set, $workoutId);

        // 3. Max Volume PR (Set volume)
        $this->updateMaxVolumeSetPR($user, $exerciseId, $set, $workoutId);
    }

    protected function updateMaxWeightPR(User $user, int $exerciseId, Set $set, int $workoutId): void
    {
        if (! $set->weight) {
            return;
        }

        $existingPR = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->where('type', 'max_weight')
            ->first();

        if (! $existingPR || $set->weight > $existingPR->value) {
            $this->savePR($user, $exerciseId, 'max_weight', $set->weight, $set->reps, $workoutId, $set->id);
        }
    }

    protected function updateMax1RMPR(User $user, int $exerciseId, Set $set, int $workoutId): void
    {
        if (! $set->weight || ! $set->reps) {
            return;
        }

        $oneRM = $this->calculate1RM($set->weight, $set->reps);

        $existingPR = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->where('type', 'max_1rm')
            ->first();

        if (! $existingPR || $oneRM > $existingPR->value) {
            $this->savePR($user, $exerciseId, 'max_1rm', $oneRM, $set->weight, $workoutId, $set->id);
        }
    }

    protected function updateMaxVolumeSetPR(User $user, int $exerciseId, Set $set, int $workoutId): void
    {
        if (! $set->weight || ! $set->reps) {
            return;
        }

        $volume = $set->weight * $set->reps;

        $existingPR = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->where('type', 'max_volume_set')
            ->first();

        if (! $existingPR || $volume > $existingPR->value) {
            $this->savePR($user, $exerciseId, 'max_volume_set', $volume, null, $workoutId, $set->id);
        }
    }

    protected function savePR(User $user, int $exerciseId, string $type, float $value, ?float $secondary, int $workoutId, int $setId): void
    {
        PersonalRecord::updateOrCreate(
            [
                'user_id' => $user->id,
                'exercise_id' => $exerciseId,
                'type' => $type,
            ],
            [
                'value' => $value,
                'secondary_value' => $secondary,
                'workout_id' => $workoutId,
                'set_id' => $setId,
                'achieved_at' => now(),
            ]
        );
    }

    /**
     * Estimated 1RM using Epley formula.
     */
    public function calculate1RM(float $weight, int $reps): float
    {
        if ($reps <= 0) {
            return 0;
        }
        if ($reps === 1) {
            return $weight;
        }

        return round($weight * (1 + $reps / 30), 2);
    }
}
