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
 * This service is responsible for calculating and synchronizing Personal Records
 * for a user based on their completed sets. It determines 1 Rep Max (1RM),
 * max volume sets, and max weight PRs, and triggers notifications when a new PR is achieved.
 */
final class PersonalRecordService
{
    use CalculatesOneRepMax;

    /**
     * Synchronize personal records for a given set.
     *
     * @param  \App\Models\Set  $set  The set to evaluate for personal records.
     * @param  \App\Models\User|null  $user  The user to whom the set belongs (optional).
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
     * Update a specific type of personal record if the new value is higher.
     *
     * @param  \App\Models\User  $user  The user achieving the PR.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  string  $type  The type of PR (e.g., 'max_weight', 'max_1rm', 'max_volume_set').
     * @param  float  $value  The value achieved in the current set.
     * @param  float|null  $secondary  A secondary value (like reps) for the PR, if applicable.
     * @param  \App\Models\Set  $set  The set that triggered the PR check.
     * @param  \App\Models\PersonalRecord|null  $pr  The existing personal record to compare against.
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
     * Determine if personal record synchronization should be skipped for a given set.
     *
     * @param  \App\Models\Set  $set  The set to evaluate.
     * @return bool True if sync should be skipped, false otherwise.
     */
    private function shouldSkipSync(Set $set): bool
    {
        return $set->is_warmup || ! $set->weight || ! $set->reps;
    }

    /**
     * Process potential personal record updates for max weight, 1RM, and max volume.
     *
     * @param  \App\Models\User  $user  The user whose PRs are being updated.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  \App\Models\Set  $set  The set to evaluate against existing PRs.
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
