<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;

/**
 * Service for managing Personal Records (PRs).
 */
final class PersonalRecordService
{
    public function syncSetPRs(Set $set, ?User $user = null): void
    {
        if ($set->is_warmup || ! $set->weight || ! $set->reps) {
            return;
        }

        // Prevent N+1 queries by eager loading necessary relationships if not already loaded
        $set->loadMissing(['workoutLine.workout.user', 'workoutLine.exercise']);

        $workoutLine = $set->workoutLine;

        // @phpstan-ignore-next-line
        if (! $workoutLine || ! $workoutLine->workout) {
            return;
        }

        /** @var \App\Models\User|null $user */
        $user ??= $workoutLine->workout->user;

        // @phpstan-ignore-next-line
        if (! $user) {
            return;
        }

        $exerciseId = $workoutLine->exercise_id;

        if (! $exerciseId) {
            return;
        }

        $existingPRs = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->get()
            ->keyBy('type');

        $this->update($user, $exerciseId, 'max_weight', (float) $set->weight, (float) $set->reps, $set, $existingPRs->get('max_weight'));
        $this->update($user, $exerciseId, 'max_1rm', $set->reps > 1 ? round($set->weight * (1 + $set->reps / 30), 2) : (float) $set->weight, (float) $set->weight, $set, $existingPRs->get('max_1rm'));
        $this->update($user, $exerciseId, 'max_volume_set', (float) ($set->weight * $set->reps), null, $set, $existingPRs->get('max_volume_set'));
    }

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
}
