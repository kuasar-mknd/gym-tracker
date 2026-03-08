<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Services\StatsService;

final class UpdateWorkoutAction
{
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Update the given workout with new data.
     *
     * @param  array{started_at?: string|null, name?: string|null, notes?: string|null, is_finished?: bool}  $data
     */
    public function execute(Workout $workout, array $data): Workout
    {
        $workout->fill(collect($data)->only(['started_at', 'name', 'notes'])->toArray());

        // Check what changed to determine cache invalidation strategy
        $needsFullClear = $workout->isDirty(['started_at', 'ended_at']);
        $needsMetaClear = $workout->isDirty(['name']);

        if ($data['is_finished'] ?? false) {
            $workout->ended_at = now();
            $needsFullClear = true;
        }

        $workout->save();

        if ($needsFullClear) {
            // started_at/ended_at change affects volume, duration and meta (histories)
            $this->statsService->clearWorkoutRelatedStats($workout->user);
            $this->statsService->clearWorkoutMetadataStats($workout->user);
        } elseif ($needsMetaClear) {
            $this->statsService->clearWorkoutMetadataStats($workout->user);
        }

        return $workout;
    }
}
