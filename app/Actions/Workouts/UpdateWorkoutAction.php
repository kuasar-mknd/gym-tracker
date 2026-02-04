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
        $old = [$workout->name, $workout->started_at, $workout->ended_at];
        if ($data['is_finished'] ?? false) {
            $data['ended_at'] = now();
        }
        $workout->update(collect($data)->only(['started_at', 'name', 'notes', 'ended_at'])->toArray());

        $structural = ! $workout->started_at->equalTo($old[1]) || (string) $workout->ended_at !== (string) $old[2];

        if ($structural) {
            $this->statsService->clearWorkoutRelatedStats($workout->user);
        } elseif ($workout->name !== $old[0]) {
            $this->statsService->clearWorkoutMetadataStats($workout->user);
        } else {
            \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$workout->user_id}");
        }

        return $workout;
    }
}
