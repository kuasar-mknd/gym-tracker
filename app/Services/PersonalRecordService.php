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
     * Rebuilds an exercise's records from the sets that actually exist.
     *
     * update() only ever raises a record, and nothing ever lowered one. A single
     * mistyped weight — 500 for 50 — became that exercise's personal record
     * permanently: correcting the set did nothing, deleting it did nothing, and
     * the figure stayed on the user's profile for good.
     *
     * Only called when the set behind a record changes or goes, so the cost is
     * paid on the rare event rather than on every save.
     *
     * @param  list<string>|null  $types  Limit the rebuild to these records. Null
     *                                    means all of them, which is right when a set has gone and there is no
     *                                    longer any way to know which records it was holding.
     */
    public function recompute(User $user, int $exerciseId, ?array $types = null): void
    {
        $sets = Set::query()
            ->join('workout_lines', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->join('workouts', 'workouts.id', '=', 'workout_lines.workout_id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exerciseId)
            ->where('sets.is_warmup', false)
            ->where('sets.weight', '>', 0)
            ->where('sets.reps', '>', 0)
            ->select('sets.*')
            ->with('workoutLine')
            ->get();

        /** @var array<string, callable(Set): array{0: float, 1: float|null}> $measures */
        $measures = [
            'max_weight' => fn (Set $set): array => [(float) $set->weight, (float) $set->reps],
            'max_1rm' => fn (Set $set): array => [
                $this->calculate1RM((float) $set->weight, (int) $set->reps),
                (float) $set->weight,
            ],
            'max_volume_set' => fn (Set $set): array => [(float) $set->weight * (int) $set->reps, null],
        ];

        foreach ($measures as $type => $measure) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }

            $record = PersonalRecord::where('user_id', $user->id)
                ->where('exercise_id', $exerciseId)
                ->where('type', $type)
                ->first();

            $best = null;
            $bestValue = null;
            $bestSecondary = null;

            foreach ($sets as $set) {
                [$value, $secondary] = $measure($set);

                if ($bestValue === null || $value > $bestValue) {
                    $bestValue = $value;
                    $bestSecondary = $secondary;
                    $best = $set;
                }
            }

            if ($best === null || $bestValue === null) {
                // Nothing left that qualifies; the record no longer stands.
                $record?->delete();

                continue;
            }

            $record ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $exerciseId, 'type' => $type]);

            /**
             * No notification here. This is a correction, not an achievement —
             * telling someone they have set a personal record because they just
             * fixed a typo would be worse than saying nothing.
             */
            $record->fill([
                'value' => $bestValue,
                'secondary_value' => $bestSecondary,
                'workout_id' => $best->workoutLine?->workout_id,
                'set_id' => $best->id,
                'achieved_at' => $best->created_at ?? now(),
            ])->save();
        }
    }

    /**
     * Recomputes only when the set that changed is the one a record points at.
     *
     * Safe to call on every save: the lookup is a single indexed existence
     * check, and almost every save is of a set holding nothing.
     */
    public function refreshRecordsHeldBy(Set $set, ?User $user = null): void
    {
        /**
         * Only the records this set is actually holding. Rebuilding all three
         * would reach past the change and reset records that nothing about this
         * set affects — a set that beats the 1RM but not the max weight would
         * drag the max weight down with it.
         */
        // Backing values, not enum instances: `type` is cast, so a strict
        // comparison against the string keys below would never match and the
        // rebuild would quietly select nothing.
        /** @var list<string> $types */
        $types = PersonalRecord::where('set_id', $set->id)
            ->pluck('type')
            ->map(fn (mixed $type): string => $type instanceof \BackedEnum ? (string) $type->value : (is_string($type) ? $type : ''))
            ->filter()
            ->values()
            ->all();

        if ($types === []) {
            return;
        }

        $this->refreshFor($set, $user, $types);
    }

    /**
     * Recomputes unconditionally, for when the set is on its way out.
     *
     * The gate above cannot be used after a deletion: personal_records.set_id is
     * resolved by the database the moment the row goes, so by the time the
     * `deleted` event fires nothing still admits the set held anything.
     */
    /**
     * @param  list<string>|null  $types
     */
    public function refreshFor(Set $set, ?User $user = null, ?array $types = null): void
    {
        $set->loadMissing(['workoutLine.workout.user']);
        $exerciseId = $set->workoutLine?->exercise_id;
        $user ??= $set->workoutLine?->workout?->user;

        if (! $user instanceof User || ! $exerciseId) {
            return;
        }

        $this->recompute($user, (int) $exerciseId, $types);
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
