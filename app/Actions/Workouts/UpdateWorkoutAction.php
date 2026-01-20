<?php

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Services\StatsService;

class UpdateWorkoutAction
{
    public function __construct(protected StatsService $statsService) {}

    public function execute(Workout $workout, array $data): Workout
    {
        if (isset($data['started_at'])) {
            $workout->started_at = $data['started_at'];
        }

        if (isset($data['name'])) {
            $workout->name = $data['name'];
        }

        if (isset($data['notes'])) {
            $workout->notes = $data['notes'];
        }

        if (! empty($data['is_finished']) && $data['is_finished']) {
            $workout->ended_at = now();
        }

        $workout->save();

        $this->statsService->clearUserStatsCache($workout->user);

        return $workout;
    }
}
