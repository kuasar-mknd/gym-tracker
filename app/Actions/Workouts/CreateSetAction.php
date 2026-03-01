<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Services\StatsService;

class CreateSetAction
{
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Execute the action.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, WorkoutLine $workoutLine, array $data): Set
    {
        /** @var Set $set */
        $set = $workoutLine->sets()->create($data);

        $this->statsService->clearWorkoutRelatedStats($user);

        return $set;
    }
}
