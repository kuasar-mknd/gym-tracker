<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Services\StatsService;

final class CreateSetAction
{
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Create a new set for a workout line.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, WorkoutLine $workoutLine, array $data): Set
    {
        $set = $workoutLine->sets()->create(
            collect($data)->except('workout_line_id')->toArray()
        );

        // Bolt: Only clear volume-related stats for set additions
        $this->statsService->clearVolumeStats($user);

        return $set;
    }
}
