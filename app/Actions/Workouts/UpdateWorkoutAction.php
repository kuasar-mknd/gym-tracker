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
        $needsDashboardClear = $workout->isDirty(['notes']);

        if ($data['is_finished'] ?? false) {
            $workout->ended_at = now();
            $needsFullClear = true;
        }

        $workout->save();

        if ($needsFullClear) {
            $this->statsService->clearWorkoutVolumeStats($workout->user);
            $this->statsService->clearWorkoutDurationStats($workout->user);
            $this->statsService->clearDashboardStats($workout->user);
        } elseif ($needsMetaClear) {
            // Name changes affect the dashboard's recent activity list.
            // While Volume History charts also display names, we prioritize performance here
            // by only invalidating the dashboard cache. The charts will eventually update when other stats change.
            $this->statsService->clearDashboardStats($workout->user);
        } elseif ($needsDashboardClear) {
            $this->statsService->clearDashboardStats($workout->user);
        }

        return $workout;
    }
}
