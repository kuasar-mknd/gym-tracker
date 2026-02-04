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
        $oldName = $workout->name;
        $oldStartedAt = $workout->started_at;
        $oldEndedAt = $workout->ended_at;

        $workout->fill(collect($data)->only(['started_at', 'name', 'notes'])->toArray());

        if ($data['is_finished'] ?? false) {
            $workout->ended_at = now();
        }

        $workout->save();

        // Surgical cache invalidation
        $dateChanged = !$workout->started_at->equalTo($oldStartedAt);
        $completionChanged = ($workout->ended_at && $oldEndedAt)
            ? !$workout->ended_at->equalTo($oldEndedAt)
            : $workout->ended_at !== $oldEndedAt;

        if ($dateChanged || $completionChanged) {
            // Structural changes affect volume, dates, and all statistics
            $this->statsService->clearWorkoutRelatedStats($workout->user);
        } elseif ($workout->name !== $oldName) {
            // Name changes only affect dashboard and lists containing the name
            $this->statsService->clearWorkoutMetadataStats($workout->user);
        } else {
            // If only notes changed (or nothing), only the dashboard data needs invalidation
            // (Recent workouts list in dashboard might contain stale notes in JSON, though not displayed)
            \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$workout->user_id}");
        }

        return $workout;
    }
}
