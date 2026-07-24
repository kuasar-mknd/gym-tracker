<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use App\Traits\CalculatesOneRepMax;

/**
 * Service for managing Personal Records (PRs).
 *
 * This service calculates and updates a user's personal records (e.g., max weight,
 * max 1RM, max volume set) after a workout set is completed. It also handles
 * dispatching notifications when new records are achieved.
 */
final class PersonalRecordService
{
    use CalculatesOneRepMax;

    /**
     * Synchronize personal records based on a completed set.
     *
     * Evaluates the given set against the user's existing personal records for the
     * associated exercise. If the set establishes a new record for any tracked metric,
     * the corresponding PersonalRecord is created or updated.
     *
     * @param  \App\Models\Set  $set  The workout set to evaluate for potential PRs.
     * @param  \App\Models\User|null  $user  The user who performed the set (optional, resolved from set if null).
     */
    public function syncSetPRs(Set $set, ?User $user = null): void
    {
        if ($this->shouldSkipSync($set)) {
            return;
        }

        $set->loadMissing(['workoutLine.workout.user', 'workoutLine.exercise']);

        $workout = $set->workoutLine->workout;

        if (! $workout) {
            return;
        }

        $user ??= $workout->user;
        $user->loadMissing('notificationPreferences');
        $exerciseId = $set->workoutLine->exercise_id;

        if (! $user || ! $exerciseId) {
            return;
        }

        $this->processUpdates($user, (int) $exerciseId, $set);
    }

    /**
     * Create or update a specific personal record type.
     *
     * Compares the new value against the existing record (if any). If the new value
     * is greater, it persists the new record and optionally sends a notification
     * to the user if they have PR notifications enabled.
     *
     * @param  \App\Models\User  $user  The user achieving the PR.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  string  $type  The type of PR (e.g., 'max_weight', 'max_1rm', 'max_volume_set').
     * @param  float  $value  The primary value of the new record.
     * @param  float|null  $secondary  An optional secondary value (e.g., reps associated with max weight).
     * @param  \App\Models\Set  $set  The set that achieved this record.
     * @param  \App\Models\PersonalRecord|null  $pr  The existing personal record, if any.
     */
    protected function update(User $user, int $exerciseId, string $type, float $value, ?float $secondary, Set $set, ?PersonalRecord $pr): void
    {
        if ($pr && $value <= $pr->value) {
            return;
        }

        $pr ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $exerciseId, 'type' => $type]);
        $pr->fill(['value' => $value, 'secondary_value' => $secondary, 'workout_id' => $set->workoutLine->workout_id, 'set_id' => $set->id, 'achieved_at' => now()])->save();

        if ($user->isNotificationEnabled('personal_record')) {
            $user->notify(new PersonalRecordAchieved($pr));
        }
    }

    /**
     * Determine if a set should be excluded from PR evaluation.
     *
     * Warmup sets, or sets missing either weight or reps, are not considered
     * valid for setting personal records.
     *
     * @param  \App\Models\Set  $set  The set to check.
     * @return bool True if the set should be skipped, false otherwise.
     */
    private function shouldSkipSync(Set $set): bool
    {
        return $set->is_warmup || ! $set->weight || ! $set->reps;
    }

    /**
     * Process all tracked PR metrics for a valid set.
     *
     * Retrieves existing PRs for the user and exercise, then evaluates the set
     * against each tracked metric (max weight, estimated 1RM, max volume per set).
     *
     * @param  \App\Models\User  $user  The user who performed the set.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  \App\Models\Set  $set  The completed valid set.
     */
    private function processUpdates(User $user, int $exerciseId, Set $set): void
    {
        $existingPRs = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->get()
            ->keyBy('type');

        $this->update($user, $exerciseId, 'max_weight', (float) $set->weight, (float) $set->reps, $set, $existingPRs->get('max_weight'));
        $this->update($user, $exerciseId, 'max_1rm', $this->calculate1RM((float) $set->weight, (int) $set->reps), (float) $set->weight, $set, $existingPRs->get('max_1rm'));
        $this->update($user, $exerciseId, 'max_volume_set', (float) ($set->weight * $set->reps), null, $set, $existingPRs->get('max_volume_set'));
    }
}
