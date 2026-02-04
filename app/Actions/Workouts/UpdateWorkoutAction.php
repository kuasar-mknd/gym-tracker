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

        if ($data['is_finished'] ?? false) {
            $workout->ended_at = now();
        }

        $workout->save();

        if ($workout->wasChanged('started_at')) {
            $this->statsService->clearWorkoutRelatedStats($workout->user);
        } else {
            if ($workout->wasChanged(['name', 'notes', 'ended_at'])) {
                $this->statsService->clearDashboardCache($workout->user);
            }

            if ($workout->wasChanged('name')) {
                $this->statsService->clearWorkoutNameDependentStats($workout->user);
            }

            if ($workout->wasChanged('ended_at')) {
                $this->statsService->clearWorkoutDurationDependentStats($workout->user);
            }
        }

        return $workout;
    }
}
