<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;

/**
 * Service for managing Personal Records (PRs).
 *
 * This service handles the evaluation and recording of different types of personal records
 * achieved during workout sessions. It currently supports:
 * - Max Weight: The heaviest weight lifted for a specific exercise.
 * - Max 1RM (Estimated): The highest estimated One Rep Max using the Epley formula.
 * - Max Volume: The highest volume (Weight * Reps) achieved in a single set.
 */
final class PersonalRecordService
{
    /**
     * Check and record PRs for a specific set.
     *
     * This method evaluates the completed set against existing records for the
     * user and exercise. It triggers checks for all supported PR types.
     *
     * PERFORMANCE OPTIMIZATION: Fetches all PR types in a single query and
     * skips processing for non-relevant sets (warmups, missing data).
     *
     * @param  Set  $set  The completed set to evaluate.
     * @param  User|null  $user  The user who performed the set (optional, for performance).
     */
    public function syncSetPRs(Set $set, ?User $user = null): void
    {
        // Early return for non-relevant sets to save DB queries
        if ($set->is_warmup || (! $set->weight && ! $set->reps)) {
            return;
        }

        $user = $user ?: $set->workoutLine->workout->user;
        $exerciseId = $set->workoutLine->exercise_id;

        // Fetch all existing PRs for this user and exercise in a single query
        $existingPRs = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->get()
            ->keyBy('type');

        $workoutId = $set->workoutLine->workout_id;

        // 1. Max Weight PR
        $this->updateMaxWeightPR($user, $exerciseId, $set, $workoutId, $existingPRs->get('max_weight'));

        // 2. Max 1RM PR (Estimated)
        $this->updateMax1RMPR($user, $exerciseId, $set, $workoutId, $existingPRs->get('max_1rm'));

        // 3. Max Volume PR (Set volume)
        $this->updateMaxVolumeSetPR($user, $exerciseId, $set, $workoutId, $existingPRs->get('max_volume_set'));
    }

    /**
     * Calculate Estimated 1RM using the Epley formula.
     *
     * Formula: Weight * (1 + Reps / 30)
     */
    public function calculate1RM(float $weight, int $reps): float
    {
        return match (true) {
            $reps <= 0 => 0.0,
            $reps === 1 => $weight,
            default => round($weight * (1 + $reps / 30), 2),
        };
    }

    /**
     * Update Max Weight PR if the current set's weight is higher than the existing record.
     *
     * @param  User  $user  The user who performed the set.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  Set  $set  The set being evaluated.
     * @param  int  $workoutId  The ID of the current workout.
     * @param  PersonalRecord|null  $existingPR  The current record, if any.
     */
    protected function updateMaxWeightPR(User $user, int $exerciseId, Set $set, int $workoutId, ?PersonalRecord $existingPR = null): void
    {
        if (! $set->weight) {
            return;
        }

        if (! $existingPR || $set->weight > $existingPR->value) {
            $this->savePR($user, $exerciseId, 'max_weight', $set->weight, $set->reps, $workoutId, $set->id, $existingPR);
        }
    }

    /**
     * Update Estimated 1RM PR if the current set's e1RM is higher than the existing record.
     *
     * Uses the calculate1RM method to determine the estimated one rep max.
     *
     * @param  User  $user  The user who performed the set.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  Set  $set  The set being evaluated.
     * @param  int  $workoutId  The ID of the current workout.
     * @param  PersonalRecord|null  $existingPR  The current record, if any.
     */
    protected function updateMax1RMPR(User $user, int $exerciseId, Set $set, int $workoutId, ?PersonalRecord $existingPR = null): void
    {
        if (! $set->weight || ! $set->reps) {
            return;
        }

        $oneRM = $this->calculate1RM($set->weight, $set->reps);

        if (! $existingPR || $oneRM > $existingPR->value) {
            $this->savePR($user, $exerciseId, 'max_1rm', $oneRM, $set->weight, $workoutId, $set->id, $existingPR);
        }
    }

    /**
     * Update Max Volume Set PR if the current set's volume (weight * reps) is higher than the existing record.
     *
     * @param  User  $user  The user who performed the set.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  Set  $set  The set being evaluated.
     * @param  int  $workoutId  The ID of the current workout.
     * @param  PersonalRecord|null  $existingPR  The current record, if any.
     */
    protected function updateMaxVolumeSetPR(User $user, int $exerciseId, Set $set, int $workoutId, ?PersonalRecord $existingPR = null): void
    {
        if (! $set->weight || ! $set->reps) {
            return;
        }

        $volume = $set->weight * $set->reps;

        if (! $existingPR || $volume > $existingPR->value) {
            $this->savePR($user, $exerciseId, 'max_volume_set', $volume, null, $workoutId, $set->id, $existingPR);
        }
    }

    /**
     * Persist the Personal Record to the database and notify the user.
     *
     * If a record of the same type exists, it updates it; otherwise, it creates a new one.
     * Sends a `PersonalRecordAchieved` notification if enabled by the user.
     *
     * @param  User  $user  The user who achieved the PR.
     * @param  int  $exerciseId  The exercise ID.
     * @param  string  $type  The type of PR ('max_weight', 'max_1rm', 'max_volume_set').
     * @param  float  $value  The primary value of the record (e.g., weight, 1RM, volume).
     * @param  float|null  $secondary  Secondary value for context (e.g., reps for max_weight).
     * @param  int  $workoutId  The workout ID.
     * @param  int  $setId  The set ID.
     * @param  PersonalRecord|null  $pr  The existing record to update, if available.
     */
    protected function savePR(User $user, int $exerciseId, string $type, float $value, ?float $secondary, int $workoutId, int $setId, ?PersonalRecord $pr = null): void
    {
        // Use the passed PR record if available to avoid another database query
        $pr = $pr ?: new PersonalRecord([
            'user_id' => $user->id,
            'exercise_id' => $exerciseId,
            'type' => $type,
        ]);

        $pr->fill([
            'value' => $value,
            'secondary_value' => $secondary,
            'workout_id' => $workoutId,
            'set_id' => $setId,
            'achieved_at' => now(),
        ])->save();

        if ($user->isNotificationEnabled('personal_record')) {
            $user->notify(new PersonalRecordAchieved($pr));
        }
    }
}
